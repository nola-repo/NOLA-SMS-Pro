# GHL tokens, user profiles, and legacy installs

This document matches the **Phase 4** canonical token model: OAuth secrets live only in `ghl_tokens`, while `users` / `agency_users` hold profile and pointers.

## Collections

### `ghl_tokens/{id}` (canonical)

- **Subaccount (location) tokens:** document ID = GHL **location id**. This is the primary read path for `/api/ghl-contacts`, webhooks, and SMS sync.
- **Agency tokens:** document ID = GHL **company id** (used by agency-level API calls such as `sync_locations`).

Important fields (non-exhaustive):

| Field | Role |
|--------|------|
| `access_token`, `refresh_token`, `expires_at` | OAuth state (rotated on refresh) |
| `client_id` / `appId` | Which GHL app installed the token (routes refresh credentials) |
| `userType` | `Location` vs company context |
| `companyId` | GHL company that owns the location |
| `location_id` | Redundant copy of location id for queries and legacy migration |
| `appType` | e.g. `agency` for the company-level install row |

### `users/{uid}` (subaccount / marketplace user)

Profile and scoping (example fields from QA):

- `active_location_id` — GHL location the UI should call APIs with
- `company_id` — GHL company
- `role` — e.g. `user`
- `source` — e.g. `marketplace_install`
- `ghl_token_ref` — optional **`ghl_tokens/{locationId}`** — when `Authorization: Bearer` is sent to GHL-backed routes, OAuth is loaded from this doc after access checks (`active_location_id`, ref id, etc.)

Raw OAuth tokens should **not** be duplicated here long term.

### `agency_users/{uid}` (agency)

- `company_id` — GHL company id
- `role` — e.g. `agency`
- `source`
- `ghl_token_ref` — optional **`ghl_tokens/{companyId}`** for the agency OAuth doc (must match `company_id` when both are set).

## JWT + webhook secret (`X-Webhook-Secret`)

Endpoints that validate `validate_api_request()` can also accept **`Authorization: Bearer <jwt>`**:

- **`auth_get_optional_jwt_context()`** verifies the JWT when present (401 if invalid).
- **`auth_assert_ghl_api_location_allowed()`** enforces:
  - **users:** requested `locationId` matches `active_location_id` / `ghl_token_ref`; profile must expose at least one of these bindings.
  - **agency_users:** location belongs to `company_id`; `ghl_token_ref` id must equal `company_id`.
- **`auth_resolve_ghl_token_registry_id()`** picks the OAuth Firestore doc: subaccounts prefer `ghl_tokens/{refs}` → default `locationId`; agencies **prefer existing `ghl_tokens/{requestedLocation}`** when it belongs to the agency (correct API scope), else **`ghl_token_ref`** → `company_id`.

Wired endpoints include **`/api/ghl-contacts`**, **`/api/ghl-conversations`**, **`api/webhook/ghl_create_conversation.php`**, and GHL-facing paths in **`api/webhook/send_sms.php`**.

## Legacy fallback (one release)

If `ghl_tokens/{locationId}` is missing but another document has `location_id == locationId`, the backend:

1. Loads the legacy row (logged as `[GHL_TOKEN] legacy_fallback_lookup`).
2. **Backfills** `ghl_tokens/{locationId}` via merge (logged as `[GHL_TOKEN] legacy_fallback_backfill`).
3. Continues using the canonical id for refresh and API calls.

Run `tmp/backfill_ghl_tokens_canonical.php` to bulk-merge obvious legacy rows.

## Refresh locking

Concurrent requests share **`ghl_oauth_refresh_locks`** documents:

- Company-backed locations serialize refresh on **`company_{companyId}`** when the company refresh token is used.
- Otherwise **`token_{firestoreDocId}`** is used.

Peers wait, re-read Firestore, and reuse the new access token when possible.

## `requires_reconnect` contract

The API returns:

```json
{ "error": "Token refresh failed", "requires_reconnect": true }
```

only when refresh fails with a **non-transient auth error** (e.g. `invalid_grant`) after a proper OAuth refresh attempt, not for mere access-token expiry (silent refresh), transient HTTP errors, or **refresh-lock wait timeouts** (those yield **503** without `requires_reconnect`).

See also: `docs/frontend_handoff_ghl_contacts_requires_reconnect.md`.
