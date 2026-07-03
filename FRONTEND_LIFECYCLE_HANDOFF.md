# Frontend handoff: installation, authentication, and lifecycle reliability

Date: 2026-07-02

## Objective

The frontend must use one deterministic lifecycle before loading any NOLA SMS
Pro functionality:

```text
Resolve GHL location
  -> Bootstrap location
  -> Perform required action
  -> Bootstrap again
  -> Load product only when LOCATION_READY
```

The frontend must not independently guess whether a location is installed,
registered, authenticated, or ready.

## 1. Use bootstrap as the single application gate

Call this endpoint before loading the dashboard or any product data:

```http
GET /api/v2/location/bootstrap?location_id={locationId}
Authorization: Bearer {token} # optional on the first request
X-GHL-Location-ID: {locationId}
```

Follow `next_action` exactly:

| `next_action` | Frontend behavior |
|---|---|
| `load_app` | Render the application and begin product API requests. |
| `run_autologin` | Run GHL autologin once, save the returned session, then repeat bootstrap. |
| `complete_registration` | Show the registration-required screen and use only a server-issued onboarding URL. |
| `show_not_installed` | Show Marketplace installation/reinstallation instructions. |
| `show_reconnect` | Show the GHL reconnect action. |
| `show_retry` | Show a retryable state with bounded backoff. |

Contacts, conversations, SMS, templates, billing, credits, notifications, and
settings requests must not run until bootstrap returns:

```json
{
  "code": "LOCATION_READY",
  "next_action": "load_app"
}
```

## 2. Resolve only a real GHL location ID

Accepted explicit keys:

- `location_id`
- `locationId`
- `ghl_location_id`
- `ghlLocationId`
- `active_location_id`
- `activeLocationId`
- `/v2/location/{locationId}/...` URL path

Do not treat these as a location ID:

- Generic `id`
- Generic `location` strings
- `company_id` or `companyId`
- Agency/account IDs
- Numeric internal IDs
- Values found inside generic `company`, `account`, or `message` objects

Validate candidates with:

```ts
/^[A-Za-z0-9_-]{12,80}$/
```

Priority order:

1. Current GHL URL/path
2. Valid, trusted GHL parent message
3. Current authenticated session
4. Stored location ID only as a final fallback

The current URL must overwrite stale storage. Never allow stored data to
override a different location supplied by the current GHL context.

## 3. Secure iframe and `postMessage` handling

- Accept messages only from approved HighLevel/LeadConnector origins and the
  expected parent window.
- Require explicit location fields; do not recursively search arbitrary data.
- Log the selected source (`url`, `path`, `postMessage`, `session`, or
  `storage`) without logging tokens or personal information.
- Ignore template placeholders such as `{{location.id}}`.

## 4. Authentication state machine

On initial application load:

1. Resolve the location ID.
2. Read the stored NOLA JWT, if present.
3. Call bootstrap.
4. When bootstrap returns `run_autologin`, call:

```http
GET /api/v2/auth/ghl_autologin?location_id={locationId}
```

5. Save the returned JWT, role, company ID, location ID, and user profile.
6. Repeat bootstrap exactly once with the new JWT.
7. Load the application only after `load_app`.

Prevent infinite loops:

- Maximum one automatic autologin attempt per resolved location per page load.
- Maximum three bootstrap retries for temporary failures.
- Suggested retry delays: 500 ms, 1 second, and 2 seconds.
- Stop retrying on registration, installation, company mismatch, reconnect, or
  authorization errors.

An initial `/api/auth/me` response of `401` is not fatal while bootstrap or
autologin is still running.

## 5. Keep agency and location sessions separate

Agency session:

- Role must be `agency`.
- Must contain the correct `company_id`.
- May access only locations confirmed by the backend as belonging to that
  company.

Location-user session:

- Role must be `user`.
- Must contain the canonical `location_id`.
- Must never be reused after switching to another GHL location.

When the resolved location changes:

1. Pause all product requests.
2. Cancel or ignore in-flight responses for the previous location.
3. Clear location-scoped cached data.
4. Run bootstrap/autologin for the new location.
5. Resume product loading only after `LOCATION_READY`.

## 6. Installation, registration, and reinstall UX

- Never construct or modify an install JWT in the browser.
- Use only onboarding URLs issued by the backend/Marketplace callback.
- On `LOCATION_ALREADY_REGISTERED`, redirect to the returned `login_url`.
- On `INSTALL_TOKEN_EXPIRED`, restart Marketplace installation; do not keep
  resubmitting the expired page.
- On `INSTALL_TOKEN_INVALID`, discard the link and restart installation.
- Reinstalling a registered location must route to login, not allow another
  owner account.
- Display agency ID and location ID as separate labeled values in diagnostics.

After the backend deployment, onboarding links remain valid for one hour.

## 7. Session and storage requirements

Canonical keys:

```text
nola_auth_token
nola_auth_role
nola_company_id
nola_location_id
nola_auth_user
```

Requirements:

- Treat storage as a cache, not an authority.
- Clear the old token/profile when role, company, or location changes.
- Remove JWTs from the browser URL immediately with `history.replaceState`.
- Do not write tokens to logs, analytics, error tracking, or query strings after
  the initial secure handoff.
- Gracefully support restricted iframe storage with an in-memory fallback.

## 8. Product API request requirements

Every location-scoped request must include:

```http
Authorization: Bearer {token}
X-GHL-Location-ID: {locationId}
X-Request-ID: {bounded client request ID}
```

Also include `location_id` in the query/body where the existing endpoint
contract requires it.

SMS requirements:

- Send an `Idempotency-Key` for every send request.
- Use a unique key per recipient send.
- Keep one shared `batch_id` for a bulk-send operation.
- Do not retry an uncertain SMS result with a new idempotency key.
- Show provider failures as failed delivery attempts rather than silently
  removing them.

Ignore stale responses after the active location changes. A response for
location A must never update location B's UI.

## 9. Error handling contract

Handle backend `code`, not message text:

| Code | Required behavior |
|---|---|
| `LOCATION_READY` | Load application. |
| `GHL_AUTOLOGIN_REQUIRED` | Run autologin once. |
| `LOCATION_REGISTRATION_REQUIRED` | Show onboarding action. |
| `LOCATION_NOT_INSTALLED` | Show Marketplace install action. |
| `LOCATION_INSTALL_PENDING` | Retry with bounded backoff. |
| `LOCATION_COMPANY_MISMATCH` | Stop and show support-ready diagnostics. |
| `LOCATION_SESSION_MISMATCH` | Clear session and run autologin once. |
| `GHL_RECONNECT_REQUIRED` | Show reconnect action. |
| `INSTALL_TOKEN_EXPIRED` | Restart Marketplace installation. |
| `INSTALL_TOKEN_INVALID` | Restart Marketplace installation. |
| `LOCATION_ALREADY_REGISTERED` | Redirect to `login_url`. |
| `LOCATION_BOOTSTRAP_FAILED` | Show retry; preserve current data safely. |

All error screens should show a copyable request ID, location ID, and error
code. Never display tokens, secrets, or raw provider responses.

## 10. Loading and concurrency requirements

- Show one lifecycle loading screen until bootstrap resolves.
- Use `AbortController` for location-scoped requests.
- Deduplicate simultaneous bootstrap, profile, credits, contacts, and
  conversations requests.
- Do not convert temporary API failures into zero balances or empty datasets.
- Keep the last successful data visible with a non-blocking stale-data warning
  when supported by the API.

## 11. Required acceptance tests

### Installation and authentication

- New direct sub-account install completes registration and opens the app.
- Registered sub-account reinstall routes to login.
- Agency bulk install creates usable location access for every selected
  sub-account.
- Authenticated agency user opens an installed child location.
- Location user opens its own location.
- Location user is rejected from a sibling location.
- Deleted/stale owner recovery allows safe registration.
- Active owner cannot be replaced.
- Expired onboarding link shows restart-installation behavior.
- Uninstalled location cannot load the app or send SMS.

### Location switching

- Switch between two sub-accounts without refreshing.
- Confirm old requests are cancelled or ignored.
- Confirm contacts, credits, messages, and settings all belong to the new
  location.
- Confirm stale local storage never overrides the current GHL location.

### Product functionality

- Contacts load and stale-cache responses render correctly.
- Single and bulk SMS sends are idempotent.
- Delivery status updates appear in conversations.
- Templates remain location-scoped.
- Credits and billing never display another location's data.
- Sender requests and notifications remain location-scoped.
- Reconnect flow restores GHL access without creating another NOLA account.

## 12. Release requirements

Do not release the frontend unless:

- All acceptance tests above pass in staging.
- Firebase authorizes every production frontend domain.
- The GHL custom page/menu link exists and opens without `404`.
- The deployed build uses bootstrap before product requests.
- Source maps and monitoring are enabled without exposing secrets.
- Rollback to the previous frontend build has been tested.

## Backend references

- `api/location/bootstrap.php`
- `api/services/LocationBootstrapService.php`
- `api/auth/ghl_autologin.php`
- `api/auth/register_from_install.php`
- `api/install_helpers.php`
- `docs/INSTALL_LIFECYCLE.md` / lifecycle contract
