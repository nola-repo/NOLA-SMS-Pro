# Backend handoff: GHL token canonical storage and automatic refresh

Status: Ready for backend investigation  
Priority: Critical, because GHL access tokens expire daily  
Related frontend handoffs:
- `backend_handoff_ghl_manual_reconnect.md`
- `frontend_handoff_settings_ghl_fix.md`

## Executive summary

Do not rely on users reconnecting every day. GHL access tokens are short lived, but refresh tokens exist so the backend can refresh silently.

The likely bug is not "tokens expire daily"; that is normal. The likely bug is one or more of:

- tokens are stored under the wrong Firestore document ID
- `/api/ghl-contacts` reads a different token document than OAuth writes
- refresh-token rotation is not persisted after refresh
- agency tokens and location tokens are mixed
- refresh is attempted with the wrong GHL app client credentials
- concurrent requests race and one request invalidates the other request's refresh token

## What the frontend does now

The frontend now supports a clean fallback path, but it expects the backend to handle normal token expiry automatically.

Current frontend behavior:

1. Contacts calls `GET /api/ghl-contacts`.
2. If the response is `200`, Contacts renders the returned contacts or the real empty state.
3. If the response is `401` with `requires_reconnect: true`, Contacts shows a "GoHighLevel connection expired" banner instead of "No contacts available".
4. The banner's `Open Settings` button:
   - stores a local reconnect-required flag
   - opens Settings > Account
5. Settings shows a short reconnect notice and a visible `Reconnect GoHighLevel` button.
6. `Reconnect GoHighLevel`:
   - stores a return target of `contacts`
   - redirects to the GHL Marketplace OAuth location picker
7. GHL redirects to `/oauth/callback?code=...`.
8. `GhlCallback.tsx` posts the code to `POST /api/ghl_oauth`.
9. If backend succeeds:
   - frontend saves local connected state and returned `locationId`
   - clears the reconnect-required flag
   - redirects back to `/contacts`
10. Contacts calls `/api/ghl-contacts` again.

This means the frontend only needs `requires_reconnect` when backend has truly exhausted automatic refresh. Do not return reconnect for normal daily access-token expiry.

## Decision: do not migrate raw tokens into `users` or `agency_users`

The new `users` and `agency_users` collections are good identity/profile collections. They should not become the primary token store.

Recommended split:

| Collection | Purpose | Should contain tokens? |
|---|---|---|
| `users/{uid}` | Subaccount user profile, active location, app login profile | No |
| `agency_users/{uid}` | Agency login profile, company ID, app login profile | No |
| `ghl_tokens/{locationId}` | Canonical subaccount/location OAuth token | Yes |
| `ghl_tokens/{companyId}` | Canonical agency/company OAuth token | Yes, for agency app only |
| `integrations/*` | Legacy/compatibility mirror or migration source | Prefer no new writes unless still required |

Why: tokens are secrets and operational credentials. Keep them in a restricted canonical collection with clear document IDs and security rules. `users` and `agency_users` should store references such as `active_location_id`, `company_id`, `token_doc_ref`, and display/profile fields.

## Canonical token keys

### Location/subaccount installs

Use:

```text
ghl_tokens/{locationId}
```

Required fields:

```json
{
  "appType": "location",
  "owner_type": "user",
  "owner_user_id": "<users uid>",
  "location_id": "<GHL location id>",
  "company_id": "<GHL company id if known>",
  "location_name": "<optional>",
  "access_token": "<secret>",
  "refresh_token": "<secret>",
  "expires_at": 1770000000000,
  "client_id": "<GHL user/location app client id>",
  "scope": "<oauth scope>",
  "updated_at": 1770000000000
}
```

### Agency/company installs

Use:

```text
ghl_tokens/{companyId}
```

Required fields:

```json
{
  "appType": "agency",
  "owner_type": "agency",
  "owner_user_id": "<agency_users uid>",
  "company_id": "<GHL company id>",
  "company_name": "<optional>",
  "access_token": "<secret>",
  "refresh_token": "<secret>",
  "expires_at": 1770000000000,
  "client_id": "<GHL agency app client id>",
  "scope": "<oauth scope>",
  "updated_at": 1770000000000
}
```

Do not store a company-level token under a location ID. Do not store a location-level token under a user UID.

## How backend should resolve tokens

### For user/location APIs such as `/api/ghl-contacts`

1. Resolve requested location ID from:
   - `X-GHL-Location-ID`
   - `locationId`
   - `location_id`
   - user profile `active_location_id` only as fallback
2. Verify the authenticated user is allowed to access that location:
   - `users/{uid}.active_location_id == locationId`, or
   - `users/{uid}.subaccounts` contains the location, or
   - `location_members/{locationId}_{uid}` exists, depending on the current auth model
3. Read canonical token:

```text
ghl_tokens/{locationId}
```

4. If not found, fallback once for legacy docs:
   - query `ghl_tokens` where `location_id == locationId`
   - legacy `integrations/ghl_{locationId}` or `users/{uid}/integrations/ghl`
5. If a legacy token is found, immediately backfill/merge it into `ghl_tokens/{locationId}`.
6. Use canonical token for all future calls.

### For agency APIs

1. Resolve company ID from:
   - authenticated `agency_users/{uid}.company_id`
   - request company/agency ID only after auth validation
2. Read canonical token:

```text
ghl_tokens/{companyId}
```

3. Do not use company token for location contacts unless the endpoint is explicitly an agency-level GHL API and the scopes permit it.

## OAuth write rules

`POST /api/ghl_oauth`, `ghl_callback.php`, `oauth_exchange.php`, and any similar callback must key token documents from the GHL token response, not from user input.

For location OAuth:

```php
$locationId = $result['locationId'] ?? $result['location_id'] ?? null;
$companyId = $result['companyId'] ?? $result['company_id'] ?? null;

if (!$locationId) {
    // This is not a location token. Do not write it to ghl_tokens/{userInputLocationId}.
}

$tokenRef = $db->collection('ghl_tokens')->document($locationId);
```

For agency OAuth:

```php
$companyId = $result['companyId'] ?? $result['company_id'] ?? null;

if (!$companyId) {
    throw new RuntimeException('Agency OAuth did not return companyId');
}

$tokenRef = $db->collection('ghl_tokens')->document($companyId);
```

Also write the profile references:

```text
users/{uid}.active_location_id = locationId
users/{uid}.company_id = companyId
users/{uid}.ghl_token_ref = "ghl_tokens/{locationId}"

agency_users/{uid}.company_id = companyId
agency_users/{uid}.ghl_token_ref = "ghl_tokens/{companyId}"
```

These are references only, not token copies.

## Automatic refresh requirements

Every backend GHL API client should call a shared token provider, for example:

```php
$token = $tokenProvider->getValidAccessTokenForLocation($locationId);
```

The provider should:

1. Read `ghl_tokens/{locationId}`.
2. If `expires_at` is more than 5 minutes in the future, return `access_token`.
3. If expired or near expiry, acquire a per-token refresh lock.
4. Re-read the document after lock acquisition in case another request already refreshed it.
5. Refresh with GHL using the stored `refresh_token`.
6. Use the stored `client_id` or `appType` to choose the correct GHL client credentials.
7. Persist the new `access_token`, new `refresh_token`, and new `expires_at` atomically.
8. Release the lock.
9. Retry the original API request once.

Important: GHL refresh tokens rotate. If the refresh response includes a new `refresh_token`, save it immediately. Never keep using the old refresh token.

## Refresh lock

Add lock fields to the token document:

```json
{
  "refresh_lock_until": 1770000000000,
  "refresh_lock_owner": "request-id-or-host-id"
}
```

Use a Firestore transaction:

1. If no lock or lock expired, set lock for 30-60 seconds.
2. If lock is active, wait briefly and re-read token.
3. If token was refreshed by another request, use the new token.
4. If lock expires, one request may retry acquiring it.

This avoids two concurrent `/api/ghl-contacts` calls both using the same refresh token. Without a lock, the first refresh can rotate the token and the second refresh can fail with `invalid_grant`, causing a false reconnect state.

## Migration/backfill plan

### Phase 1: Audit

Export or inspect token-like documents from:

- `ghl_tokens`
- `integrations`
- `users/*/integrations/ghl`
- any `ghl_*` OAuth collection used by older callbacks

For each document, record:

- document path
- document ID
- `location_id`
- `company_id`
- `appType`
- `client_id`
- `expires_at`
- has `refresh_token`
- owner user ID if known

Flag mismatches:

- doc ID is a user UID but field has `location_id`
- doc ID is a company ID but field has `location_id`
- doc ID is a location ID but field has `company_id` only
- missing `client_id` or `appType`
- duplicate docs for the same `location_id`

### Phase 2: Backfill canonical docs

For each location token:

```text
source has location_id = loc_123
write/merge into ghl_tokens/loc_123
```

For each agency token:

```text
source has company_id = cmp_123 and no location_id
write/merge into ghl_tokens/cmp_123
```

Do not delete legacy docs in the first pass. Add:

```json
{
  "migrated_to": "ghl_tokens/<canonical id>",
  "migrated_at": 1770000000000
}
```

### Phase 3: Update profile references

For subaccount users:

```text
users/{uid}.active_location_id
users/{uid}.company_id
users/{uid}.ghl_token_ref = "ghl_tokens/{locationId}"
```

For agency users:

```text
agency_users/{uid}.company_id
agency_users/{uid}.ghl_token_ref = "ghl_tokens/{companyId}"
```

### Phase 4: Cut readers to canonical

Update every endpoint that calls GHL to use the shared token provider and canonical read path:

- `/api/ghl-contacts`
- `/api/ghl-conversations`
- workflow/send SMS paths that call GHL
- agency subaccount list paths if they call GHL directly
- any webhook action that uses GHL tokens

Keep legacy fallback for one release. Log whenever fallback is used.

## When to return `requires_reconnect`

Only return:

```json
{
  "error": "Token refresh failed",
  "requires_reconnect": true
}
```

when all are true:

1. canonical token doc exists
2. refresh token exists
3. refresh was attempted with the correct client credentials
4. refresh failed with a non-transient GHL auth error such as `invalid_grant`
5. no concurrent refresh lock is active that might resolve shortly

Do not return reconnect just because the access token expired. That should be handled silently.

## QA scenarios

### Normal daily expiry

1. Set `expires_at` in `ghl_tokens/{locationId}` to the past.
2. Keep `refresh_token` valid.
3. Call `/api/ghl-contacts`.
4. Expected:
   - backend refreshes token
   - saves new `access_token`
   - saves new `refresh_token`
   - updates `expires_at`
   - returns `200`
   - frontend never shows reconnect banner

### Wrong document ID

1. Put valid token in a legacy doc with `location_id = loc_123` but wrong document ID.
2. Call `/api/ghl-contacts` for `loc_123`.
3. Expected:
   - backend finds legacy fallback
   - backfills `ghl_tokens/loc_123`
   - returns `200`
   - logs migration warning

### Refresh race

1. Expire token.
2. Fire 5 parallel `/api/ghl-contacts` requests.
3. Expected:
   - only one request calls GHL refresh endpoint
   - other requests wait/re-read
   - all requests return `200`
   - no false `requires_reconnect`

### Truly revoked token

1. Revoke/uninstall app or invalidate refresh token.
2. Call `/api/ghl-contacts`.
3. Expected:
   - backend attempts refresh once
   - backend returns `401` with `requires_reconnect: true`
   - frontend shows reconnect banner
   - Settings `Reconnect GoHighLevel` restores fresh token

## Backend deliverables

- Shared GHL token provider/service
- Canonical token read/write path
- Firestore migration/backfill script
- Refresh-token rotation persistence
- Per-location/company refresh lock
- Clear logs for token lookup source and refresh outcomes
- Updated docs for `users`, `agency_users`, `ghl_tokens`, and legacy `integrations`

## Notes from current Firestore shape

The new `users` document shown in QA has:

```text
active_location_id
company_id
role = "user"
source = "marketplace_install"
```

The new `agency_users` document shown in QA has:

```text
company_id
role = "agency"
source = "marketplace_install"
```

That is a good profile model. Use those fields to resolve and authorize access, but keep raw GHL OAuth credentials in canonical `ghl_tokens` documents.
