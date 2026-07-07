# NOLA SMS Pro Frontend Requirements - Backend Alignment

**Status:** Implementation handoff

**Audience:** User-app frontend, agency-app frontend, frontend QA

**Backend reviewed:** July 7, 2026

**Canonical user app:** `https://app.nolacrm.io`

**Canonical API:** `https://smspro-api.nolacrm.io`

The complete user/agency frontend source is not present in this backend
checkout. Apply this handoff in the corresponding frontend repositories; the
root-level TypeScript files here are not a complete buildable application.

## 1. Objective

The frontend must never infer that a location is ready merely because NOLA SMS
Pro is visible in the HighLevel sidebar. HighLevel controls Marketplace
installation and visibility. The NOLA backend controls onboarding,
authentication, ownership, token health, and product access.

Every embedded user-app load must follow this lifecycle:

```text
Resolve trusted GHL location
  -> Call location bootstrap
  -> Perform exactly the requested next_action
  -> Bootstrap again when instructed
  -> Load product data only after LOCATION_READY / load_app
```

Until the backend returns `load_app`, the frontend must not load contacts,
conversations, templates, tickets, credits, notifications, Sender IDs, account
settings, or SMS features.

## 2. Backend model the frontend must follow

### 2.1 Marketplace installation is not NOLA activation

These are separate states:

| External/internal state | Meaning | Frontend access |
|---|---|---|
| HighLevel app visible | HighLevel recorded Allow & Install | Lifecycle screen only |
| `PENDING_OAUTH` / `INSTALL_PENDING` | OAuth, token exchange, selection, or registration is incomplete | No product access |
| `TOKEN_ONLY` | Location token exists but no canonical NOLA owner is registered | Registration-required screen |
| `INSTALLED` + linked account + `is_live: true` | NOLA installation is complete | Continue bootstrap/auth checks |
| `UNINSTALLED` or `is_live: false` | HighLevel access was removed or disabled | Not-installed/reinstall screen |
| `cleanup_in_progress: true` | Controlled cleanup is underway | Product and send actions disabled |

The browser redirect is not the source of truth. A user may close the tab after
the backend succeeds or before it succeeds. Always use the backend state.

### 2.2 Single canonical owner model

- One GHL location has exactly one canonical NOLA location user.
- One location user cannot own a second GHL location.
- Reinstalling a registered location must route to its existing owner login.
- A second registration receives `409 LOCATION_ALREADY_REGISTERED`.
- Regular users must never see an account chooser or a list of linked users.
- The old multi-user sub-account handoff is superseded by this document.

Agency accounts remain separate and may access multiple authorized locations.

## 3. Required frontend architecture

Implement one top-level lifecycle boundary above all protected routes:

```text
GhlContextResolver
  -> LifecycleProvider
      -> LifecycleGate
          -> AuthProvider
              -> ProductRoutes
```

Recommended state shape:

```ts
type LifecyclePhase =
  | 'resolving_context'
  | 'bootstrapping'
  | 'autologin'
  | 'registration_required'
  | 'install_pending'
  | 'not_installed'
  | 'reconnect_required'
  | 'retryable_error'
  | 'blocked'
  | 'ready';

type LifecycleState = {
  phase: LifecyclePhase;
  locationId: string | null;
  companyId: string | null;
  code: string | null;
  requestId: string | null;
  message: string | null;
  retryCount: number;
};
```

Do not scatter independent install/auth checks across Dashboard, Contacts,
Compose, Settings, and other pages.

## 4. Trusted GHL context resolution

Accepted location keys:

- `location_id`
- `locationId`
- `ghl_location_id`
- `ghlLocationId`
- `active_location_id`
- `activeLocationId`
- `/v2/location/{locationId}/...` URL paths

Validate with:

```ts
/^[A-Za-z0-9_-]{12,80}$/
```

Never treat these as location IDs:

- generic `id` or `location` values;
- `company_id` / `companyId`;
- agency/account IDs;
- numeric-only internal IDs;
- contact, conversation, or message IDs; or
- template placeholders such as `{{location.id}}`.

Resolution priority:

1. Current trusted HighLevel URL/path.
2. Trusted `postMessage` from the expected parent and approved HighLevel or
   LeadConnector origin.
3. Current authenticated session.
4. Stored location only as a final fallback.

The current GHL context always overrides stale storage.

When the location changes, immediately:

1. pause product requests;
2. abort or ignore in-flight requests for the old location;
3. clear old location caches and session data;
4. bootstrap the new location; and
5. resume only after `LOCATION_READY`.

## 5. Location bootstrap: mandatory product gate

### Request

```http
GET /api/v2/location/bootstrap?location_id={locationId}
Authorization: Bearer {token}          # optional on first call
X-GHL-Location-ID: {locationId}
X-Request-ID: {uuid}
```

Equivalent legacy endpoint: `/api/location/bootstrap.php`.

### Ready response

```json
{
  "success": true,
  "status": "ready",
  "code": "LOCATION_READY",
  "location_id": "...",
  "contacts_can_load": true,
  "next_action": "load_app",
  "role": "user",
  "company_id": "...",
  "token_ready": true
}
```

Only this response permits product rendering and product API calls.

### `next_action` handling

| `next_action` | Required frontend behavior |
|---|---|
| `load_app` | Render protected routes and start product requests. |
| `run_autologin` | Run location autologin once, save session, repeat bootstrap once. |
| `complete_registration` | Show registration-required UI. Use only a server-issued onboarding URL. |
| `show_not_installed` | Show install/reinstall instructions and Marketplace action. |
| `show_reconnect` | Show GHL reconnect action; do not create another NOLA user. |
| `show_retry` | Show bounded retry UI; preserve last safe state. |

### Retry limits

- Maximum one automatic autologin attempt per location per page load.
- Maximum three automatic bootstrap retries.
- Suggested delays: 500 ms, 1 second, and 2 seconds.
- Never loop on registration, uninstall, invalid token, company mismatch,
  inactive account, or reconnect errors.

## 6. Required lifecycle screens

### 6.1 Resolving / verifying workspace

Show a neutral full-page loader while context and bootstrap run. Do not briefly
render the dashboard underneath it.

Suggested copy: `Verifying your NOLA SMS Pro workspace...`

### 6.2 Setup incomplete

Use for `complete_registration`, `TOKEN_ONLY`, or
`LOCATION_REGISTRATION_REQUIRED`.

Required content:

- `NOLA SMS Pro is installed in HighLevel, but account setup is incomplete.`
- location name/ID when safe;
- **Continue Setup** using a backend-issued URL;
- **Restart Installation** when no valid onboarding URL exists; and
- copyable error code and request ID.

The frontend must not create or synthesize an install JWT or registration URL.

### 6.3 Installation still processing

Use for `LOCATION_INSTALL_PENDING`.

- Explain that the HighLevel installation is visible but NOLA provisioning is
  still completing.
- Retry using bounded backoff.
- After automatic retries, show a manual **Try Again** action.
- Do not redirect to registration unless the backend returns that action.

### 6.4 Not installed / uninstalled

Use for `LOCATION_NOT_INSTALLED` and `LOCATION_UNINSTALLED`.

- Show Marketplace installation or reinstallation instructions.
- Disable all product navigation and sending.
- Never label this as a login failure.

### 6.5 Reconnect required

Use for `GHL_RECONNECT_REQUIRED`.

- Explain that the NOLA account exists but HighLevel authorization needs to be
  refreshed.
- Show a backend-provided or configured OAuth reconnect action.
- Do not offer registration or create a new owner.

### 6.6 Wrong company/location

Use for `LOCATION_COMPANY_MISMATCH` or repeated
`LOCATION_SESSION_MISMATCH`.

- Stop automatically.
- Show location ID, company ID, code, and request ID.
- Do not expose access tokens, raw payloads, or personal data.

### 6.7 Cleanup in progress

When a product/send response returns `cleanup_in_progress`:

- immediately disable Compose and all send actions;
- stop background message/contact synchronization for that location;
- show `This test workspace is temporarily unavailable while cleanup is in progress.`;
- do not retry SMS; and
- keep the location blocked until the backend no longer reports cleanup and a
  subsequent bootstrap succeeds.

Backend follow-up: location bootstrap should surface `cleanup_in_progress`
directly so the frontend can block before its first product request. Until that
contract is deployed, the first gated product response may be what triggers
this screen.

## 7. Authentication and session requirements

Canonical storage keys:

```text
nola_auth_token
nola_auth_role
nola_company_id
nola_location_id
nola_auth_user
nola_user                 # compatibility only
```

Storage is a cache, never authority.

### Location-user autologin

When bootstrap returns `run_autologin`, call the user/location endpoint once:

```http
POST /api/v2/auth/ghl_autologin
Content-Type: application/json
X-Request-ID: {uuid}

{
  "location_id": "...",
  "company_id": "...",
  "ghl_user_id": "...",
  "email": "..."
}
```

Use only identity fields explicitly obtained from trusted GHL context. After
success:

1. store the JWT and normalized profile;
2. remove any token from the browser URL using `history.replaceState`;
3. call bootstrap again; and
4. load the app only after `LOCATION_READY`.

### Agency autologin

Agency iframe authentication is separate:

```http
POST /api/agency/ghl_autologin
Content-Type: application/json

{ "company_id": "..." }
```

Do not use `/api/auth/ghl_autologin` for the agency app. After success, fetch:

```http
GET /api/agency/profile.php
Authorization: Bearer {agencyToken}
```

Agency and location-user sessions must never overwrite or reuse each other.

### Profile hydration

Use the auth handoff payload for immediate display, then self-heal from
`/api/auth/me` or the agency profile endpoint.

Profile field fallbacks:

```ts
const fullName = user.full_name || user.name;
const email = user.email_address || user.email;
const phone = user.phone_number || user.phone;
```

Do not show fake placeholder identity such as `agency@example.com` while a
profile request is loading or failed.

## 8. Registration and reinstall requirements

### Verify onboarding token

```http
GET /api/auth/verify-install-token?token={installToken}
```

The returned location and company fields are locked display values. The user
must not edit them.

### Submit registration

```http
POST /api/auth/register-from-install
Content-Type: application/json
X-Request-ID: {uuid}

{
  "full_name": "...",
  "email": "...",
  "phone": "...",
  "password": "...",
  "install_token": "..."
}
```

Client validation:

- all four identity/password fields required;
- valid email;
- password at least eight characters;
- confirmation must match; and
- prevent repeated submits while the request is unresolved.

### Registration result handling

- Success: save session only when the backend reports the account ready, then
  bootstrap the exact returned location before rendering the dashboard.
- `LOCATION_ALREADY_REGISTERED`: navigate to returned `login_url`; never show a
  second registration form.
- `INSTALL_TOKEN_EXPIRED` / `INSTALL_TOKEN_INVALID`: discard token and show
  **Restart Marketplace Installation**.
- Company/location mismatch: stop and show support diagnostics.
- Uncertain network result: do not submit with altered identity. First
  bootstrap/recheck the location, then follow the backend action.

### Backend activation contract now implemented

The backend now enforces this activation boundary:

- user remains inactive/pending until token, integration, and owner activation
  all succeed;
- the canonical owner and active user are committed together;
- JWT is issued only after activation succeeds; and
- failed activation leaves the newly created user inactive/pending and safely
  resumable; it does not issue a JWT.

The frontend must still bootstrap the returned location before rendering the
dashboard; a registration response alone does not bypass the lifecycle gate.

## 9. API client requirements

All location-scoped calls:

```http
Authorization: Bearer {token}
X-GHL-Location-ID: {locationId}
X-Request-ID: {uuid}
```

Also send `location_id` in the query/body where the endpoint requires it.

The shared client must:

- parse JSON error `code` and `next_action`;
- globally handle expired/invalid JWTs;
- distinguish `401` from `403`;
- never turn temporary errors into an empty list or zero balance;
- abort old-location requests with `AbortController`;
- deduplicate bootstrap, profile, credits, contacts, conversations, and
  notification requests; and
- expose a copyable request ID in error UI.

## 10. Product behavior aligned with backend changes

### SMS and Compose

- Send one unique `Idempotency-Key` per recipient send.
- Use one shared `batch_id` for all recipients in a bulk operation.
- Do not retry an uncertain SMS outcome with a new idempotency key.
- No frontend minimum message length is required.
- Validate supported Philippine mobile format and non-empty content.
- Display character count, segment count, and estimated credits.
- Preserve special characters.
- Provider rejection may still create a failed message record; show the failed
  attempt rather than deleting it or reporting generic success.
- Treat `install_pending`, `app_uninstalled`, `not_installed`,
  `cleanup_in_progress`, and insufficient-credit codes as blocked sends.

### Sender IDs

- `NOLASMSPro` is always the platform default sender.
- Remove **Set as Default** for custom Sender IDs.
- Approved custom senders remain available for explicit per-send selection.
- Pending and rejected Sender IDs must never be selectable.
- Request names must be alphanumeric and 3-11 characters.

### Contacts

- Contacts remain scoped to the verified location.
- A backend response may include `stale: true` and `warning` when returning the
  last successful GHL contact cache.
- Render stale contacts with a non-blocking warning; do not erase the list.
- After reconnect or location switch, refetch from the verified location.

### Conversations and delivery status

- Keep failed provider attempts visible with `Failed` status.
- Status mapping must remain `Sending`, `Sent`, and `Failed`.
- Ignore status updates whose location or conversation no longer matches the
  active context.

### Credits and billing

- Preserve the last successful balance when refresh fails.
- Never replace the balance with zero because of an API/network error.
- Disable purchase/request/send actions unless lifecycle is `ready`.
- After payment success, refresh both balance and ledger.
- Do not treat popup opening as payment success.

### Settings and profile

- In an iframe, Location ID comes from verified GHL context and is read-only.
- Outside GHL, reconnect/manual setup must still pass bootstrap before saving a
  usable location.
- Agency Settings must load the agency JWT/profile even inside the iframe.

### Notifications, templates, and tickets

- Load only after `LOCATION_READY`.
- Persist and refetch by the active location.
- Never reuse cached data after switching location.

## 11. Error-code contract

| Backend code | Frontend action |
|---|---|
| `LOCATION_READY` | Load product application. |
| `GHL_AUTOLOGIN_REQUIRED` | Autologin once, then bootstrap again. |
| `LOCATION_SESSION_MISMATCH` | Clear location session, autologin once. |
| `LOCATION_REGISTRATION_REQUIRED` | Show setup-incomplete screen. |
| `LOCATION_INSTALL_PENDING` | Bounded retry, then manual retry. |
| `LOCATION_ONBOARDING_EXPIRED` | Restart Marketplace installation. |
| `LOCATION_CLEANUP_IN_PROGRESS` | Block product UI; retry only after cleanup. |
| `LOCATION_NOT_INSTALLED` | Show Marketplace installation action. |
| `LOCATION_UNINSTALLED` | Show reinstall action; block product. |
| `LOCATION_COMPANY_MISMATCH` | Stop and show diagnostics. |
| `LOCATION_INACTIVE` | Block access; contact admin/support. |
| `GHL_RECONNECT_REQUIRED` | Show reconnect action. |
| `GHL_TOKEN_TEMPORARILY_UNAVAILABLE` | Preserve state and retry manually/bounded. |
| `LOCATION_BOOTSTRAP_FAILED` | Retry screen; do not clear good data. |
| `INVALID_GHL_LOCATION_ID` | Stop; report context-detection problem. |
| `INSTALL_TOKEN_EXPIRED` | Restart Marketplace install. |
| `INSTALL_TOKEN_INVALID` | Restart Marketplace install. |
| `LOCATION_ALREADY_REGISTERED` | Navigate to returned login URL. |
| `cleanup_in_progress` | Disable product/send and wait for fresh bootstrap. |
| `app_uninstalled` / `not_installed` | Exit product UI and show install action. |

Use backend codes, not message-string matching.

## 12. Telemetry and privacy

Log only:

- lifecycle phase;
- backend code and HTTP status;
- request ID;
- location/context source;
- retry count; and
- elapsed time.

Never log:

- JWTs, OAuth codes, install tokens, access/refresh tokens;
- passwords;
- full webhook or `postMessage` payloads;
- message bodies; or
- unnecessary personal information.

## 13. Required frontend tests

## 13A. Backend release delta for frontend implementation

The following backend changes are included in the release represented by this
handoff. Frontend teams should use this as the short implementation checklist.

### Installation and registration

- Registration validates the exact installed `location_id` and requires a
  usable Location-scoped OAuth token before account activation.
- New users are stored as inactive/pending until the activation batch commits.
- No JWT is returned before the user, canonical owner, token, integration, and
  subaccount are active.
- Registration can return:
  - `404 LOCATION_NOT_INSTALLED` -> restart Marketplace installation;
  - `409 LOCATION_INSTALL_PENDING` -> show pending/retry state;
  - `423 LOCATION_CLEANUP_IN_PROGRESS` -> block setup until cleanup finishes;
  - `409 LOCATION_ALREADY_REGISTERED` -> follow `login_url`.
- Required registration parameters remain `full_name`, `email`, `phone`,
  `password`, and `install_token`. Do not send editable location/company values
  as authority; the signed install token is authoritative.

### Bootstrap and cleanup

- Bootstrap now returns `423 LOCATION_CLEANUP_IN_PROGRESS` before product data
  loads when a cleanup lock is present.
- Abandoned `PENDING_OAUTH` records can become `ONBOARDING_EXPIRED` after the
  reviewed server-side reconciliation process.
- Bootstrap returns `410 LOCATION_ONBOARDING_EXPIRED`; show a restart-install
  action rather than login or automatic retry.
- Product/send endpoints can also return lowercase `cleanup_in_progress`,
  `onboarding_expired`, `app_uninstalled`, `install_pending`, or
  `not_installed`. Route these into the same lifecycle gate.
- The cleanup process preserves native HighLevel conversations but can remove
  NOLA-local cached/product data. Clear all location-scoped frontend caches
  when any cleanup/uninstall lifecycle code is received.

### SMS provider parameters and failures

- Continue sending `sendername`, `location_id`, one `Idempotency-Key` per
  recipient, and one shared `batch_id` for bulk sends.
- UniSMS generic test phrases such as `test`, `sms test`, `test message`, or
  `NOLA SMS test` are rejected before the provider call with
  `error=unisms_likely_spam`.
- Display the backend-provided natural-message guidance. Do not automatically
  resend or replace the message text.
- Short natural messages remain permitted; do not add a minimum-length rule.
- A provider-level failure may have a persisted failed message record. Show
  the failure in the conversation instead of converting the response to
  success or deleting the attempt.

### Sender and application URLs

- `NOLASMSPro` remains the default sender; custom approved senders are explicit
  per-send choices and cannot be made the stored default.
- Authentication handoff, install login, and registration now return to
  `https://app.nolacrm.io`. Remove hard-coded `app.nolasmspro.com` navigation
  from new frontend builds.

### Frontend cache reset on cleanup/uninstall

Clear or invalidate, for the affected location only:

- contacts and contact-search cache;
- conversations, messages, and delivery-status polling;
- templates and tickets;
- credits, transactions, and billing state;
- Sender ID requests/configuration;
- notification settings; and
- pending Compose recipients, message, batch ID, and idempotency keys.

Do not clear another active location's data in an agency session.

### Lifecycle and installation

- App visible in GHL while callback is unfinished -> pending screen, no product
  requests.
- Exit after Allow & Install, reopen app -> resumable deterministic state.
- Exit after callback but before registration -> setup-incomplete screen.
- Network loss during registration -> no duplicate submit or false dashboard.
- New registration -> bootstrap returns ready before dashboard loads.
- Existing-owner reinstall -> login, never second registration.
- Invalid/expired install token -> restart action.
- Uninstalled location -> no dashboard and no SMS.
- Cleanup in progress -> no sending or background sync.

### Authentication and isolation

- Stale token for location A while opening location B -> clear and autologin B.
- Location user cannot open sibling location.
- Agency user can open only backend-authorized child locations.
- Agency and user sessions remain separate.
- No infinite bootstrap/autologin loop.

### Product regressions

- Contacts render stale-cache warning without disappearing.
- Single/bulk SMS uses correct idempotency keys and batch IDs.
- Provider failure remains visible as a failed attempt.
- System sender remains default; custom approved sender is selectable per send.
- Failed credit refresh preserves last good balance.
- Old-location responses never update the new location UI.

### Browser/device

- Chrome, Edge, and Safari-equivalent storage restrictions.
- Desktop and mobile navigation.
- Third-party-cookie/storage restrictions in the HighLevel iframe.
- Popup-blocked billing flow.
- Refresh and browser-back during every lifecycle phase.

## 14. Delivery order

1. Build shared GHL context resolver.
2. Build bootstrap client and top-level LifecycleGate.
3. Add lifecycle screens and bounded retry behavior.
4. Implement location-user autologin and session isolation.
5. Correct agency iframe autologin/profile hydration.
6. Integrate registration/reinstall error contracts.
7. Gate all product queries and mutations behind `ready`.
8. Apply Sender ID, stale-contact, provider-failure, credit-preservation, and
   cleanup-state behavior.
9. Add automated lifecycle and location-isolation tests.
10. Run full staging acceptance against fresh install, abandoned install,
    reinstall, reconnect, cleanup, and uninstall scenarios.

## 15. Release blockers

Do not release the frontend until:

- the atomic registration/activation backend changes are deployed;
- the pending-install reconciler is scheduled after a reviewed dry run;
- the cleanup-aware location bootstrap change is deployed;
- the deployed frontend uses bootstrap before every product surface;
- canonical production URLs use `app.nolacrm.io`;
- all lifecycle screens are implemented;
- agency and user iframe authentication pass independently;
- location isolation tests pass; and
- install, interruption, reinstall, reconnect, cleanup, and uninstall tests pass
  in disposable HighLevel locations.

## 16. Must-address-before-beta alignment matrix

All items from the installation-control review are included in the coordinated
release scope. Some are frontend requirements, some are backend requirements,
and several require both teams.

| Must-address item | Backend responsibility | Frontend responsibility | Beta completion condition |
|---|---|---|---|
| Atomic registration and activation | Commit pending user, canonical owner, token, integration, and activation safely; issue JWT only after success; compensate failures | Keep lifecycle gate closed until successful response and subsequent `LOCATION_READY`; prevent duplicate uncertain submissions | A forced failure at every registration write point leaves no active orphan user, owner, JWT, credits, or false-ready UI |
| Incomplete-setup experience | Return authoritative `code`, `next_action`, state, and only server-issued onboarding URLs | Implement Setup Incomplete, Continue Setup, Restart Installation, pending, reconnect, and not-installed screens; never render dashboard underneath | Opening the already-visible GHL app at every incomplete phase produces one correct recovery screen and zero product requests |
| Abandoned-install handling | Define pending-install TTL; expire/reconcile stale sessions and tokens; purge unnecessary PII; optionally uninstall when authorized | Resume from backend state, handle expired onboarding, avoid local guesses, and provide restart/retry actions | Closing the browser after Allow & Install, callback, or registration can be recovered safely without duplicates or unauthorized access |
| HighLevel installation reconciliation | Periodically compare NOLA state with HighLevel installed locations; classify missing, pending, uninstalled, and mismatched records | Consume reconciliation/bootstrap results and move immediately out of product UI when backend says access is no longer valid | GHL-installed/NOLA-incomplete and NOLA-active/GHL-uninstalled drift is detected and produces the correct UI and access gate |
| Secure idempotent lifecycle events | Authenticate lifecycle webhooks; deduplicate by webhook/installation/event ID; make repeated INSTALL, callback, registration, and UNINSTALL harmless | Deduplicate lifecycle calls, lock unresolved submits, use request IDs, and never manufacture new install/session identifiers to retry uncertain results | Replaying every lifecycle request/event produces one final installation, one owner, one user, and no duplicate side effects |
| Interruption and lifecycle test matrix | Provide deterministic test fixtures/logging and failure injection at callback, token exchange, activation, uninstall, and cleanup boundaries | Automate browser exit, refresh, back navigation, storage restriction, location switching, and network interruption cases | Full staging matrix passes in disposable locations with recorded request IDs and verified Firestore/HighLevel end state |

### Ownership summary

- Frontend cannot make HighLevel delay sidebar visibility after **Allow &
  Install**.
- Backend owns whether NOLA data and capabilities become active.
- Frontend owns whether any product surface is rendered before the backend says
  `LOCATION_READY`.
- Beta approval requires both controls to pass together; neither team can close
  the installation-control concern alone.

## Backend reference files

- `api/location/bootstrap.php`
- `api/services/LocationBootstrapService.php`
- `api/auth/ghl_autologin.php`
- `api/agency/ghl_autologin.php`
- `api/auth/verify_install_token.php`
- `api/auth/register_from_install.php`
- `api/auth/me.php`
- `api/install_helpers.php`
- `api/webhook/ghl_marketplace_events.php`
- `auth-handoff.html`
- `install-register.php`
- `install-login.php`
- `SINGLE_USER_LOCATION_MODEL.md`
