# Frontend Handoff: Agency Billing CORS

## Context

Agency Billing / Credits & Wallet had different behavior depending on where the app was loaded:

- Outside the GHL Agency Portal: agency wallet balance could load, but related billing data such as transactions, credit requests, and subscription details could fail.
- Inside GHL embedded views: billing requests could be fully blocked before wallet, transaction, or credit request data loaded.

The pasted browser log showed CORS failures from:

```txt
https://agency.nolasmspro.com
```

Requests from that origin were blocked before the backend could return wallet, transaction, credit request, or subaccount data.

## Backend Adjustment Made

The backend now trusts the known product/GHL app origins:

- `https://smspro.nolacrm.io`
- `https://app.nolacrm.io`
- `https://app.nolasmspro.com`
- `https://agency.nolasmspro.com`
- `https://app.gohighlevel.com`
- `https://app.highlevel.com`

Agency billing endpoints accept a valid agency JWT when the requested `agency_id` matches the signed-in agency account. The legacy `WEBHOOK_SECRET` path remains available for internal or older callers.

Agency wallet balance reads now resolve through the same backend balance resolver used by billing endpoints. This keeps the Funding Wallet consistent when the balance exists in either the canonical `agency_users.balance` field or the legacy `agency_wallet/{agency_id}.balance` document.

The refresh-to-zero bug had two causes:

1. Backend: some callers read only `agency_users.balance` (often `0`) while the funded amount still lived in legacy `agency_wallet.balance`.
2. Frontend: a failed refresh request (CORS/auth/network) overwrote the card with `0` instead of keeping the last known value.

The admin agency-user list also uses this same resolver and supports cache bypass with `?refresh=1` or `?bypass_cache=1`.

## Frontend Expectations

Agency billing calls from the browser should send:

```ts
headers: {
  Authorization: `Bearer ${agencyToken}`,
  Accept: 'application/json',
}
```

For JSON writes, also include:

```ts
'Content-Type': 'application/json'
```

The frontend should not rely on cookies alone for agency billing data.

For the Agency Funding Wallet card in the Agency Portal, load and refresh through:

```txt
GET /api/billing/agency_wallet.php?agency_id={agencyId}
Authorization: Bearer {agencyToken}
```

For the admin agency-user list (admin panel only), bypass the 5-minute cache on manual refresh:

```txt
GET /api/admin_list_agency_users.php?refresh=1
```

Do not reset the visible wallet balance to `0` while a refresh is pending or if the refresh request fails. Keep the last known balance on screen and show the refresh error state separately. A failed CORS or auth response must not overwrite the last good balance.

## Deployment Note

If production has custom frontend or white-label origins beyond the trusted origins listed above, they still need to be included in `CORS_ALLOWED_ORIGINS`.

Use a comma-separated list, for example:

```txt
CORS_ALLOWED_ORIGINS=https://smspro.nolacrm.io,https://app.nolacrm.io,https://agency.nolasmspro.com,https://client-brand.example.com
```

## QA Checklist

- Agency wallet loads outside the GHL Agency Portal.
- Agency transactions load outside the GHL Agency Portal.
- Agency credit requests load outside the GHL Agency Portal.
- Agency subaccounts load outside the GHL Agency Portal.
- Subscription or billing summary data loads outside the GHL Agency Portal.
- Wallet, transactions, and credit request data load inside the GHL embedded view.
- Agency Funding Wallet refresh keeps the previous balance while loading and does not fall back to `0` on a failed request.
- Agency Funding Wallet refresh returns the same balance as the initial load.
- A white-label/custom frontend domain is added to `CORS_ALLOWED_ORIGINS` before testing that domain.
