# Console Error Cleanup Handoff

Date: 2026-06-19

## Purpose

This handoff converts the console inspection findings into frontend and backend action items for beta readiness.

The app is mostly working, but the browser console currently shows noisy errors during GoHighLevel subaccount inspection and switching. Some errors are third-party noise from GHL/LeadConnector/Freshworks/Sentry, while a smaller set comes from NOLA SMS Pro location/session timing and data integrity issues.

## Important Access Model

- Agency owners are the only users expected to switch between multiple GHL subaccounts.
- Normal user accounts should only access the single subaccount/location they belong to.
- User login should resolve to the user's assigned `active_location_id`.
- Agency owner flows should support changing the active viewed subaccount without accidentally using a stale user-location session.

This distinction matters for cleanup: subaccount switching should be treated as an agency-owner workflow, while normal users should not trigger repeated cross-location session recovery.

## Error Categories

### External / Third-Party Console Noise

These are not owned by NOLA SMS Pro code, but they appear during app inspection because NOLA runs inside or beside GoHighLevel.

- `PerformanceObserver does not support buffered flag with the entryTypes argument`
  - Source: LeadConnector/GHL frontend scripts.
  - Impact: Browser compatibility warning only.
  - Action: Do not treat as NOLA app failure.

- Freshworks `Cannot read properties of undefined (reading 'widget_id')`
  - Source: Freshworks widget script.
  - Impact: Third-party widget initialization issue.
  - Action: Verify whether the widget is intentionally loaded. If not needed in production, remove or defer it.

- `[Federation Runtime] shared singleton module axios/vue-i18n does not satisfy requirement`
  - Source: GHL microfrontend/module federation runtime.
  - Impact: GHL dependency warning.
  - Action: Monitor only unless it breaks embedded app behavior.

- LeadConnector WhatsApp, voice-call, and GMB `400` errors
  - Source: GHL built-in modules checking features/scopes for each location.
  - Impact: Usually means that subaccount lacks WhatsApp, voice, or GMB setup.
  - Action: Do not confuse these with NOLA SMS failures.

- Firestore `ERR_QUIC_PROTOCOL_ERROR.QUIC_TOO_MANY_RTOS`
  - Source: Browser/Firestore transport.
  - Impact: Usually transient network/HTTP3 noise.
  - Action: Monitor for user-visible realtime sync problems only.

- Sentry `429 Too Many Requests`
  - Source: GHL/Sentry ingestion.
  - Impact: Error reporting rate limited.
  - Action: Not a NOLA functional failure.

- GHL custom page link `404`
  - Source: `app.gohighlevel.com/.../custom-page-link/...`
  - Impact: Possibly stale or deleted GHL custom menu link.
  - Action: Check GHL marketplace/custom menu configuration.

### NOLA-Owned Console Errors

These should be cleaned up before broad beta testing.

#### 1. Contacts API `403`

Example:

```text
GET https://app.nolasmspro.com/api/ghl-contacts?location_id=... 403
NOLA SMS: Contacts API error: 403 {error: 'Location does not match your active_location_id.'}
```

Cause:

The frontend requests contacts for a GHL location before the NOLA session/JWT has been synchronized to that location.

Backend confirms this through `auth_assert_ghl_api_location_allowed(...)` in `api/auth_helpers.php`.

Expected behavior:

- Agency owner switches subaccount.
- App detects that the requested GHL location differs from the current NOLA session.
- App runs silent auto-login/session sync.
- Only after sync succeeds should contacts load.

Current behavior:

- Contacts load starts too early.
- Backend correctly rejects the request.
- Silent auto-login later succeeds and reloads.
- App works, but the console shows avoidable errors.

Frontend action:

- Add a location-session gate before contacts load.
- If `requestedLocationId !== session.locationId`, pause contacts fetch and run auto-login first.
- Suppress user-facing error toast for this expected transition state.

Backend action:

- Keep the `403` authorization protection.
- Optionally return a more machine-readable code such as:

```json
{
  "error": "Location does not match your active_location_id.",
  "code": "LOCATION_SESSION_MISMATCH"
}
```

#### 2. Notifications API `403`

Example:

```text
GET https://app.nolasmspro.com/api/notifications?limit=30&location_id=... 403
```

Cause:

Same as contacts: notifications are location-scoped and are requested before session sync finishes.

Frontend action:

- Put notifications behind the same location-session gate.
- Do not request notifications for an agency-selected subaccount until the agency owner session has been synced or authorized for that location.

Backend action:

- Keep location authorization strict.
- Add a structured error code for easier frontend handling.

#### 3. Silent Auto-Login Logs

Examples:

```text
[LocationContext] Triggering silent auto-login for location: ...
[LocationContext] Silent auto-login succeeded for ... Saving session and reloading.
```

Cause:

This is the recovery path, not the failure.

Impact:

It is useful during development but too noisy for beta testers and agency owners inspecting the console.

Frontend action:

- Move these logs behind a debug flag.
- Show a clean in-app loading state during agency subaccount switching, such as `Switching location...`.
- Avoid reloading more than once per successful location change.

Backend action:

- No immediate backend change required if auto-login succeeds.

#### 4. Auto-Login `409 Multiple users are linked to this location`

Example:

```text
POST /api/auth/ghl_autologin?location_id=... 409
Silent auto-login failed ... Multiple users are linked to this location.
```

Cause:

More than one user document is linked to the same GHL location. The backend intentionally refuses to choose one automatically.

Impact:

This is a real data integrity issue. It can prevent normal user login or agency-owner subaccount inspection for that location.

Backend action:

- Build or run a deduplication repair script for user records by location.
- Enforce one active normal user account per subaccount/location.
- During install/register/reinstall, prevent creating duplicate active users for the same `active_location_id`.
- Keep a clear admin/support repair path for beta.

Suggested repair behavior:

- Find all users where `active_location_id`, `location_id`, or `ghl_location_id` matches the same GHL location.
- Pick the canonical active user based on install metadata, newest valid token, or admin decision.
- Mark duplicate records inactive or merge them.
- Ensure the canonical user has the correct `active_location_id`, `company_id`, `location_name`, and `ghl_token_ref`.

Frontend action:

- If auto-login returns `409`, show a clean support message instead of continuing to load location data.
- Stop contacts/conversations/notifications requests for that location until the duplicate is repaired.

#### 5. Conversations API `500`

Example:

```text
GET https://app.nolasmspro.com/api/conversations?location_id=... 500
```

Likely causes:

- Follow-on request after auto-login failed.
- Conversation endpoint may be receiving requests before session state is valid.
- Backend timestamp formatting may be brittle if a Firestore field is not stored as the expected timestamp object.

Frontend action:

- Put conversations behind the same location-session gate.
- If auto-login fails, do not continue loading conversation data.

Backend action:

- Harden `api/conversations.php` timestamp formatting.
- Accept Firestore timestamp objects, strings, nulls, and other safe values without throwing.
- Add location authorization behavior consistent with contacts and notifications if JWT context is present.

## Frontend Handoff Actions

### 1. Create A Shared Location Session Gate

All location-scoped frontend API calls should pass through one gate.

Protected calls should include:

- `/api/ghl-contacts`
- `/api/notifications`
- `/api/conversations`
- `/api/messages`
- `/api/billing/subaccount_wallet.php`
- sender/config/settings endpoints that depend on `location_id`

Expected logic:

1. Resolve `requestedLocationId` from GHL URL/context.
2. Read current NOLA session location.
3. If user role is `user`, only allow the user's assigned location.
4. If user role is `agency`, allow subaccount switching but run agency/location session sync before data fetches.
5. Do not start location-scoped requests while sync is pending.
6. If sync succeeds, save the new session and then fetch data.
7. If sync fails, stop dependent requests and show a clean error state.

### 2. Separate User And Agency Location Behavior

Normal users:

- Should not switch subaccounts.
- Should not pull `location_id` from a random GHL URL if it differs from their assigned location.
- Should load only their assigned `active_location_id`.

Agency owners:

- Can inspect or switch subaccounts.
- Need a controlled subaccount switch state.
- Should not cause normal user session mismatch errors while browsing agency-owned locations.

### 3. Handle Expected `403` Quietly During Sync

If the backend returns `LOCATION_SESSION_MISMATCH` or the current string error, the frontend should:

- Detect whether a location sync is already in progress.
- Avoid showing scary toasts.
- Avoid retry storms.
- Wait for auto-login/sync result.

### 4. Move Debug Logs Behind A Flag

Keep useful logs in development, but reduce console noise for beta:

- `LocationContext` auto-login logs.
- Contacts API expected mismatch logs.
- Repeated retry logs.

Suggested flag:

```ts
const DEBUG_LOCATION_SYNC = import.meta.env.DEV || localStorage.getItem('nola_debug_location_sync') === '1';
```

### 5. Add Request IDs To Every API Call

This is already described in `FRONTEND_REQUEST_ID_HANDOFF.md`.

Every frontend request should include:

```http
X-Request-ID: <uuid>
```

This will make beta console reports much easier to trace in Cloud Run/backend logs.

## Backend Handoff Actions

### 1. Keep Strict Location Authorization

Do not loosen `auth_assert_ghl_api_location_allowed(...)`.

The backend is correctly protecting cross-location access. The cleanup should happen by improving frontend sequencing and backend error clarity.

### 2. Add Structured Error Codes

For location mismatch responses, return stable codes the frontend can branch on:

```json
{
  "error": "Location does not match your active_location_id.",
  "code": "LOCATION_SESSION_MISMATCH"
}
```

Other suggested codes:

- `LOCATION_NOT_AUTHORIZED`
- `DUPLICATE_LOCATION_USERS`
- `LOCATION_NOT_INSTALLED`
- `LOCATION_INACTIVE`
- `TOKEN_RECONNECT_REQUIRED`

### 3. Deduplicate User Records By Location

Before beta:

- Run a data audit for duplicate users per `active_location_id`.
- Repair locations that produce `409 Multiple users are linked to this location`.
- Add install/register guardrails so duplicates do not come back.

### 4. Harden Conversations Endpoint

In `api/conversations.php`:

- Add a safe timestamp formatter.
- Avoid calling `formatAsString()` unless the value supports it.
- Return a controlled `4xx` for auth/location issues instead of allowing follow-on `500` noise.

### 5. Confirm Agency Owner Authorization Model

Backend should clearly distinguish:

- Agency owner/company-level authorization for subaccount inspection.
- Normal user/location-level authorization for a single assigned subaccount.

Agency owners should be allowed to access subaccounts that belong to their company, while normal users should be limited to their own `active_location_id`.

## Beta Readiness Checklist

- Normal user logs in from GHL and only sees their assigned subaccount.
- Normal user cannot access another `location_id` by URL manipulation.
- Agency owner can switch between installed subaccounts.
- Switching subaccounts does not trigger visible contacts/notifications/conversations `403` console noise.
- Duplicate linked users return a clean support/admin message.
- Conversations load without `500` errors for old or mixed timestamp records.
- Third-party GHL/Freshworks/Sentry noise is documented so testers do not report it as a NOLA failure.
- Every frontend API request includes `X-Request-ID`.
- Cloud Run/backend logs can trace a beta user's issue from request ID to endpoint result.

## Recommended Priority

1. Frontend location-session gate for all location-scoped requests.
2. Backend duplicate-user audit and repair.
3. Conversations timestamp hardening.
4. Structured backend error codes.
5. Debug-log cleanup.
6. Request ID rollout.
7. GHL custom menu link verification.

## Expected Outcome

After these changes, beta testers and agency owners should see a much cleaner console. Real errors will remain visible, but expected location-switch recovery noise should disappear or become controlled debug logging.

