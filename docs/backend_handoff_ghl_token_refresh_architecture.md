# Backend handoff: GHL token refresh architecture

## Main principle

GHL access token expiry should never be a user-facing problem.

Access tokens expiring is normal. The backend should silently refresh the token, retry the original GHL request once, and only tell the frontend to show reconnect when the refresh token is truly unusable.

The frontend must never read, store, refresh, or reason about GHL OAuth tokens.

## Current repo status

Scanned backend files:

- `api/services/GhlClient.php`
- `api/services/GhlTokenProvider.php`
- `api/ghl_contacts.php`
- `api/ghl-conversations.php`
- `api/webhook/ghl_create_conversation.php`
- `api/webhook/send_sms.php`
- `api/services/GhlSyncService.php`
- `api/auth_helpers.php`
- `api/account.php`

The main token layer is already moving in the right direction:

- `GhlClient.php` performs proactive refresh before expiry.
- `GhlClient.php` retries once after a GHL `401`.
- `GhlClient.php` saves rotated `refresh_token` values after refresh.
- `GhlClient.php` uses a refresh lock through `GhlTokenRefreshLock`.
- `GhlClient.php` supports company-token to location-token exchange.
- `GhlTokenProvider.php` centralizes canonical token lookup.
- `GhlTokenProvider.php` supports legacy `location_id` fallback and backfills canonical docs.
- `GhlTokenProvider.php` classifies refresh failures as `invalid_grant`, `transient`, `missing_refresh`, `lock_active`, or `other`.
- `auth_helpers.php` resolves the token registry ID from the JWT profile when present.

## GHL facts absorbed into the strategy

Official HighLevel docs confirm the behavior we need to design around:

- Access tokens last about 24 hours.
- Refresh tokens last up to 1 year only if unused.
- When a refresh token is used, GHL returns a new refresh token and the previous refresh token becomes invalid.
- GHL recommends wrapping all API calls so expired access tokens are refreshed, stored, and retried automatically.
- OAuth/API rate limits are currently 100 requests per 10 seconds and 200,000 requests per day per Marketplace app per location/company resource.

References:

- https://marketplace.gohighlevel.com/docs/oauth/Faqs/index.html
- https://marketplace.gohighlevel.com/docs/2021-07-28/Authorization/OAuth2.0/index.html
- https://help.gohighlevel.com/support/solutions/articles/48001060529-highlevel-api-documentation

This means refresh tokens must be treated as rotating, effectively single-use credentials. A concurrent refresh bug can create its own `invalid_grant` even when the user did not disconnect the app.

## Required backend architecture

All GHL API calls should flow through the same token layer:

```text
Backend endpoint
   -> GhlClient
   -> GhlTokenProvider
   -> Firestore ghl_tokens/{id}
   -> GHL API
```

No endpoint should manually read `access_token`, call `/oauth/token`, or decide whether to refresh.

Direct reads from `ghl_tokens` are acceptable only for metadata that is not OAuth-secret behavior, such as:

- `location_name`
- `company_name`
- `toggle_enabled`
- `rate_limit`
- `attempt_count`
- agency/subaccount display metadata

Direct reads are not acceptable for:

- selecting an `access_token`
- selecting a `refresh_token`
- refreshing an OAuth token
- retrying after GHL `401`
- determining reconnect state outside the token provider/client layer

## Canonical Firestore storage

Canonical token storage should stay predictable:

```text
ghl_tokens/{locationId} -> location/subaccount token
ghl_tokens/{companyId}  -> company/agency token
```

Legacy fallback is acceptable during migration, but it should remain a temporary bridge. Long term, all active token docs should be canonical.

Recommended token fields:

```text
access_token
refresh_token
expires_at
updated_at
client_id
appId
userType
companyId
location_id
location_name
appType
scope
token_status
last_refresh_at
last_refresh_error
last_refresh_reason
refresh_fail_count
```

When refresh succeeds, update the token fields together. Partial token writes are dangerous because the app can end up with a fresh access token but stale refresh metadata.

## Failure classification

Backend should classify GHL token failures before returning anything to the frontend:

```text
expired access token + valid refresh token -> refresh silently
GHL 401 after API call -> refresh once and retry
invalid_grant -> reconnect required
invalid_grant after possible concurrent refresh -> re-read canonical doc before reconnect
missing refresh_token -> reconnect or storage/migration issue
refresh lock active -> retryable temporary failure
GHL 429/500/timeout -> retryable temporary failure
wrong scope -> use correct token doc or exchange company token to location token
our app JWT/session expired -> normal app 401
```

Important rule:

```text
Never pass raw GHL OAuth/token errors directly to the frontend.
Return a classified application error.
```

## Current response contract in this repo

`GhlClient.php` currently returns:

Reconnect required:

```json
{
  "error": "Token refresh failed",
  "requires_reconnect": true
}
```

with HTTP `401`.

Temporary token problem:

```json
{
  "error": "GHL token temporarily unavailable",
  "requires_reconnect": false
}
```

with HTTP `503`.

The frontend handoff should support this current contract.

## Recommended future response contract

For consistency across all GHL endpoints, backend should eventually standardize on:

Reconnect required:

```json
{
  "success": false,
  "code": "GHL_RECONNECT_REQUIRED",
  "requires_reconnect": true,
  "retryable": false,
  "message": "GoHighLevel needs to be reconnected."
}
```

Temporary GHL/token issue:

```json
{
  "success": false,
  "code": "GHL_TEMPORARY_FAILURE",
  "requires_reconnect": false,
  "retryable": true,
  "message": "GoHighLevel is temporarily unavailable. Please try again."
}
```

Recommended status-code meaning:

```text
401 -> user is not logged into our app or app JWT is invalid
403 -> user lacks permission for this location/company
409 or 424 -> GHL reconnect required
429/500/503 -> temporary retryable failure
```

Because the current repo already returns `401` for GHL reconnect, the frontend must support `401 + requires_reconnect: true` now. The backend can migrate to `424` later as a breaking-contract cleanup.

## Repo-specific potential issues to fix or watch

### 1. Refresh tokens are rotating and can be invalidated by concurrency

GHL refresh tokens rotate. Once a refresh token is used successfully, the old refresh token becomes invalid.

Potential issue:

```text
Request A uses refresh_token_old -> succeeds -> stores refresh_token_new
Request B also uses refresh_token_old -> receives invalid_grant
```

If Request B returns reconnect immediately, the app may tell the user to reconnect even though Request A already fixed the token.

Apply rule:

Before returning `requires_reconnect: true` for `invalid_grant`, reload the canonical `ghl_tokens/{id}` doc and check whether another request already saved a fresh token.

If a fresh token exists:

```text
use the fresh token
retry the original API request once
do not show reconnect
```

Only return reconnect when the canonical active token is still invalid after this re-read/retry.

### 2. Refresh writes must preserve the new refresh token everywhere

Because refresh tokens rotate, a successful refresh must immediately save the returned `refresh_token`. If GHL does not return one, keep the existing token only if the docs/response semantics confirm that is valid for the specific flow.

Potential issue:

```text
access_token is updated
refresh_token remains old
next refresh uses invalid old token
```

Apply rule:

After refresh, atomically update:

```text
access_token
refresh_token
expires_at
updated_at
client_id/appId
userType
companyId
location_id, if available
raw_refresh, secret-safe or access-controlled
token_status
last_refresh_at
last_refresh_reason
refresh_fail_count
```

### 3. GHL 429 is rate limiting, not reconnect

GHL documents OAuth/API limits per Marketplace app per location/company resource.

Potential issue:

High-volume Contacts, Sync, SMS, and Settings calls can trigger `429`. This should not become a reconnect prompt.

Apply rule:

Treat `429` as retryable/transient:

```json
{
  "success": false,
  "code": "GHL_TEMPORARY_FAILURE",
  "requires_reconnect": false,
  "retryable": true,
  "message": "GoHighLevel is temporarily unavailable. Please try again."
}
```

Also log or store GHL rate-limit response headers when present:

```text
X-RateLimit-Limit-Daily
X-RateLimit-Daily-Remaining
X-RateLimit-Interval-Milliseconds
X-RateLimit-Max
X-RateLimit-Remaining
```

### 4. Conversation endpoints may drop `requires_reconnect`

Files:

- `api/ghl-conversations.php`
- `api/webhook/ghl_create_conversation.php`

These endpoints call `GhlClient`, but when the GHL response is an error they wrap the response as:

```json
{
  "success": false,
  "error": "GHL API error",
  "ghl_status": 401,
  "ghl_error": "..."
}
```

Potential issue: if `GhlClient` returned `requires_reconnect: true`, that field can be hidden inside `ghl_error` instead of being exposed at the top level.

Apply rule:

When an endpoint receives an error response from `GhlClient`, decode the body. If the body contains `requires_reconnect`, preserve it at the top level.

Example:

```json
{
  "success": false,
  "code": "GHL_RECONNECT_REQUIRED",
  "requires_reconnect": true,
  "retryable": false,
  "message": "GoHighLevel needs to be reconnected."
}
```

### 5. Follow-up GHL calls are not always status-checked

Files:

- `api/ghl-conversations.php`
- `api/webhook/ghl_create_conversation.php`
- `api/services/GhlSyncService.php`

Some flows call GHL, decode the body, then continue even if the response failed. Example: after creating/finding a conversation, the code fetches contact details and assumes a valid body.

Potential issue: a reconnect/temporary token error can become an empty phone/name or a misleading local Firestore write.

Apply rule:

Every `GhlClient->request()` result should be checked before consuming the decoded body.

### 6. Direct `ghl_tokens` reads are mixed across the repo

Files include:

- `api/account.php`
- `api/account-sender.php`
- `api/admin_agencies.php`
- `api/agency/*.php`
- `api/webhook/send_sms.php`
- `api/services/GhlSyncService.php`

Many of these reads are metadata-only and acceptable. The backend team should still audit them with this rule:

```text
Metadata read -> okay
OAuth token read/refresh/use -> must go through GhlClient/GhlTokenProvider
```

### 7. Browser-visible `X-Webhook-Secret` is not strong user auth

`validate_api_request()` depends on `X-Webhook-Secret`. In the old frontend, `frontend_old/src/api/config.ts` contains `WEBHOOK_SECRET`, which means the secret is browser-visible.

Potential issue: if frontend requests omit `Authorization: Bearer <JWT>`, the backend may run with `jwtCtx === null`, and tenant/location authorization is weaker.

Apply rule:

Frontend app calls should include both:

```text
X-Webhook-Secret
Authorization: Bearer <NOLA JWT>
X-GHL-Location-ID
```

Webhook-only calls may still use webhook secret behavior where appropriate.

### 8. Missing refresh token should be separated from `invalid_grant`

`GhlOAuthRefreshException::shouldPromptReconnect()` currently does not prompt reconnect when the canonical doc does not exist, refresh token is missing, or refresh was not attempted with client credentials.

Potential issue: this prevents false reconnect prompts, but missing `refresh_token` may still leave the user stuck unless operational health fields/logs make it visible.

Apply rule:

Mark and log missing-refresh cases clearly:

```text
token_status = refresh_failed
last_refresh_reason = missing_refresh
requires_reconnect = false or true depending on product decision
```

If missing refresh token means the user cannot recover without OAuth, product may decide to return reconnect required after confirming the canonical doc is the correct active doc.

### 9. Company/location scope can still be mixed

`GhlClient.php` has safeguards for company-backed locations, copied tokens, and location-token exchange. This is good, but scope errors can still happen if the caller passes the wrong `locationId` or token registry ID.

Apply rule:

For GHL contacts and conversations, the API location ID should be the location/subaccount ID. The token registry ID should be resolved through `auth_resolve_ghl_token_registry_id()`.

### 10. Token health fields are not fully standardized yet

The repo has good logging and classification, but not a complete token health status contract across documents.

Apply rule:

Add/update health fields during refresh success/failure:

```text
token_status
last_refresh_at
last_refresh_error
last_refresh_reason
refresh_fail_count
requires_reconnect
```

This avoids debugging only from Cloud Run logs.

### 11. Background refresh is not yet guaranteed by the request path

Request-time refresh works, but users can still hit a slow first request when the token is near expiry.

Apply rule:

Add a scheduled token health job:

```text
find tokens expiring in the next 30-60 minutes
refresh healthy tokens proactively
limit concurrency per location/company
respect 429/rate-limit headers with backoff
mark transient failures as retryable
mark confirmed invalid_grant as reconnect_required
log missing refresh tokens for migration/reconnect review
```

## Refresh strategy to apply

Use a single-flight refresh strategy per token registry ID or company token:

```text
1. Load canonical token.
2. If access token is fresh, use it.
3. If token is near expiry, acquire refresh lock.
4. After lock acquisition, re-read canonical token.
5. If another request already refreshed it, use the new token.
6. Otherwise refresh with GHL.
7. Save the new access_token and refresh_token atomically.
8. Retry the original GHL API call once.
9. On invalid_grant, re-read canonical token before declaring reconnect.
10. On 429/5xx/network, return retryable temporary failure.
```

This strategy directly addresses rotating refresh tokens and prevents one stale worker from forcing a false reconnect.

## Backend implementation checklist

- Keep all active GHL API calls on `GhlClient`.
- Preserve `requires_reconnect` when wrapping `GhlClient` errors.
- Add `retryable` and `code` fields consistently across GHL endpoints.
- Check every `GhlClient->request()` status before decoding/using data.
- Keep refresh lock behavior.
- Save rotated refresh tokens immediately.
- Re-read the canonical token doc after acquiring the refresh lock.
- Re-read the canonical token doc before returning reconnect on `invalid_grant`.
- Treat `429` as retryable, not reconnect.
- Capture GHL rate-limit headers in logs/telemetry where possible.
- Add token health fields on refresh success and failure.
- Audit direct `ghl_tokens` reads and classify them as metadata-only or token-sensitive.
- Add JWT `Authorization` requirement for browser app calls where missing.
- Keep `401 + requires_reconnect` supported until frontend and backend agree on a future `424` migration.

## Backend test matrix

Test these cases before considering the refresh architecture stable:

```text
healthy location token, not near expiry
location token expiring within 5 minutes
expired access token with valid refresh token
GHL 401 followed by successful refresh/retry
invalid_grant refresh response
missing refresh_token on location doc
legacy doc found by location_id and backfilled
company token refresh
company token to location token exchange
wrong company/location scope
concurrent contact requests for same location
concurrent refresh where one request succeeds and another sees old refresh token
refresh lock timeout
GHL 429 rate limit during contacts/sync
GHL 429/500/timeout during refresh
conversation creation when token requires reconnect
send_sms flow when GHL sync fails non-fatally
frontend request with JWT
frontend request without JWT
```

## Ownership rule

```text
Backend endpoints own application response shape.
GhlClient owns GHL API request/retry behavior.
GhlTokenProvider owns token lookup and refresh classification.
Firestore owns canonical token state.
Frontend only receives clean UI states.
```
