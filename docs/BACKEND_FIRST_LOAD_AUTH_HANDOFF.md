# Backend Handoff: First-Load Auth Race in GHL Iframe

Date: 2026-06-26

## Summary

The first-load bug appears as an initial app-owned request:

```text
GET https://app.nolasmspro.com/api/auth/me 401 (Unauthorized)
```

This is not, by itself, a backend failure. `api/auth/me.php` is correct to return `401` when the request has no valid JWT. The bug happens when the frontend treats that first unauthenticated profile request as final while GHL iframe location detection and auto-login are still in progress.

The backend side must guarantee that the auth endpoints are deployed, reachable, and return explicit machine-readable states so the frontend can recover without a page refresh.

## Backend Ownership

Primary backend requirement: keep the GHL auto-login and profile contract stable and observable.

Relevant files:

- `api/auth/ghl_autologin.php`
- `api/auth/me.php`
- `api/auth/user_profile_helper.php`
- `.htaccess`

## Current Backend Contract

### `GET /api/auth/me`

Expected behavior:

- No token: `401`
- Invalid or expired token: `401`
- Valid token but user doc missing: `404`
- Valid token and user found: `200` with `{ "user": ... }`

Do not change this endpoint to return `200` for unauthenticated requests. The frontend should treat `401` during first load as "session not ready yet" while GHL auto-login is pending.

### `GET|POST /api/auth/ghl_autologin`

Expected behavior:

- Valid installed location with linked user: `200` with `token`, `role`, `company_id`, `location_id`, and `user`
- Valid installed location without location user but owned by agency: `200` with an agency JWT scoped to `location_id`
- Missing location id: `400 LOCATION_ID_REQUIRED`
- Suspicious numeric/non-location id: `422 INVALID_GHL_LOCATION_ID`
- Installed token/user ambiguity: `409 DUPLICATE_LOCATION_USERS`
- Uninstalled location: `404 LOCATION_NOT_INSTALLED`
- No linked location user and no safe agency fallback: `404 LOCATION_USER_NOT_FOUND`

## Backend Checks Required

1. Confirm production deploy includes the current `api/auth/ghl_autologin.php`.
2. Confirm production deploy includes the current `api/auth/me.php`.
3. Confirm `.htaccess` preserves the `Authorization` header:

```apache
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
```

4. Confirm these routes do not 404 in production:

```text
/api/auth/me
/api/auth/ghl_autologin
```

5. Confirm Cloud Run/Apache logs show auto-login failures with enough context:

- requested `location_id`
- requested `company_id`
- whether `ghl_tokens/{location_id}` exists
- whether `integrations/ghl_{location_id}` exists
- returned `code`

## Acceptance Test

Use a known installed GHL location, for example the location visible in the console screenshot:

```text
ugBqfQsPtGijLjrmLdmA
```

Expected sequence:

1. Call `/api/auth/me` with no bearer token.
2. Response should be `401`, not `404` or `500`.
3. Call `/api/auth/ghl_autologin?location_id=ugBqfQsPtGijLjrmLdmA`.
4. Response should be either:
   - `200` with a JWT and user payload, or
   - an explicit JSON error with one of the codes listed above.
5. Call `/api/auth/me` again with `Authorization: Bearer <token from autologin>`.
6. Response should be `200` with the profile payload.

If step 3 returns `200` and step 5 returns `200`, the remaining first-load failure is frontend retry/session timing.

If step 3 returns `404`, `409`, `422`, or `500`, backend/data ownership applies and the response code should drive the repair path.

## Frontend Dependency

The frontend must retry profile loading after auto-login saves the token. Backend cannot repair a request that reaches `/api/auth/me` before a token exists.

The frontend should also extract `location_id` from GHL path URLs such as:

```text
/v2/location/{locationId}/custom-page-link/{pageId}
```

If the frontend sends no location id, or sends a company/account id as `location_id`, backend should reject it clearly rather than creating a misleading session.
