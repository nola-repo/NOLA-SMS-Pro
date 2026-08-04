# Frontend Handoff: API 401 Unauthorized Cascade

Date: 2026-08-04

## Context

After the backend commit `2159a39` (`fix(auth): move api v2 routes to stateless api middleware stack and preserve env vars across bridge`), the app showed a large number of `401 Unauthorized` responses across user, admin, and agency-facing APIs.

Observed failing calls included:

- `GET /api/credits?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/account?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/account-sender?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/sender-requests?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/conversations?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/ghl-contacts?location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/get_credit_transactions?...&location_id=ugBqfQsPtGijLjrmLdmA`
- `GET /api/templates?location_id=ugBqfQsPtGijLjrmLdmA`

Backend-side fix has been applied to ensure Laravel v2 bridge controllers forward auth/location headers into legacy PHP endpoints. Frontend still needs to validate that each protected request sends the expected auth context.

## Frontend Fix Checklist

1. Ensure the shared API client attaches auth to every protected request:

```ts
headers.Authorization = `Bearer ${token}`;
```

This applies to user, admin, agency, billing, product, and GHL contact/conversation endpoints.

2. Ensure location-scoped requests include the active location:

```ts
headers["X-GHL-Location-ID"] = activeLocationId;
```

Keep `location_id` in the query string where the endpoint expects it, but do not rely on query string alone if the API client already supports the location header.

3. If any request relies on auth cookies, include credentials:

```ts
fetch(url, {
  credentials: "include",
});
```

Bearer token auth is preferred in the GHL iframe because third-party cookie behavior can be inconsistent.

4. Block protected API calls until auth/bootstrap is complete.

Do not call these endpoints while the token or active location is still `null`, stale, or being refreshed:

- credits
- account
- account sender
- sender requests
- conversations
- messages
- templates
- GHL contacts
- credit transactions

5. On repeated `401`, stop polling and trigger session recovery.

Do not keep retrying `/api/credits` or other interval-based requests indefinitely after a `401`. The handler should:

- stop the interval/poller
- clear stale auth state if token verification fails
- call the GHL autologin/bootstrap flow when inside GHL
- show/login-route the user when outside GHL

6. Admin and agency clients must use the same auth wrapper.

Admin routes such as `/api/admin_*` and agency routes such as `/api/agency/*` should not use bare `fetch`/`axios` calls that bypass the central auth headers.

## DevTools Verification

For one failing request, open Chrome DevTools, then Network, then select the request.

Confirm Request Headers include one of:

```text
Authorization: Bearer <jwt>
```

or, only if intentionally cookie-based:

```text
Cookie: nola_auth_token=<jwt>
```

For location-scoped endpoints, confirm either:

```text
X-GHL-Location-ID: ugBqfQsPtGijLjrmLdmA
```

or:

```text
?location_id=ugBqfQsPtGijLjrmLdmA
```

Best expected state is both `Authorization` and `X-GHL-Location-ID` present.

## Acceptance Criteria

- A logged-in user can load the user dashboard with no `401` from product endpoints.
- Agency dashboard calls include bearer auth and do not return `401` unless the token is invalid.
- Admin dashboard calls include bearer/admin auth and do not return `401` unless the token is invalid.
- No endpoint polling loop continues indefinitely after `401`.
- Inside GHL iframe, a stale or missing token triggers autologin/bootstrap before protected calls run.
- Network tab confirms protected requests carry auth headers.

## Notes

The console CSP messages in the reported log were `report-only` warnings. They are noisy but not the cause of the API authorization cascade.
