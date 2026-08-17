# Multi-User Subaccount Autologin Implementation Plan

Date: 2026-06-29

## Problem

Some installed GHL subaccounts can contain multiple NOLA users. That is allowed and should remain a supported feature.

The current failure:

```json
{
  "error": "Multiple users are linked to this location.",
  "code": "DUPLICATE_LOCATION_USERS",
  "location_id": "sxtUvcl8Sm3ki3TRQz3x"
}
```

does not mean multiple users are forbidden. It means the autologin request only identified the GHL subaccount by `location_id`, and the backend could not safely determine which NOLA user should receive the session.

The safe long-term fix is to make iframe autologin resolve a specific person, not only a specific subaccount.

## Goal

Support multiple NOLA users in one GHL subaccount while ensuring each user only sees the data and account surface they are allowed to see.

Required session identity:

```text
GHL subaccount identity + current user identity
```

Preferred:

```text
location_id + ghl_user_id
```

Acceptable fallback:

```text
location_id + normalized email
```

If neither `ghl_user_id` nor email is available from the GHL iframe context, backend should fall back to the admin-selected default account for the subaccount. Regular iframe users must not see a list of linked NOLA accounts.

## Current Behavior

`api/auth/ghl_autologin.php` receives `location_id`.

It calls `LocationUserResolver::find($db, $locationId)`.

The resolver checks:

- `location_owners/{locationId}` as the canonical owner.
- Legacy user profile fields such as `active_location_id`, `location_id`, `ghl_location_id`, and `ghl_token_ref`.

If the canonical owner record is missing, stale, inactive, or ambiguous, autologin can return:

```text
409 DUPLICATE_LOCATION_USERS
```

This protects against logging the iframe visitor into the wrong account.

## Product Decision

Separate these concepts:

- Subaccount owner: the canonical billing/admin owner and default autologin account for the GHL location.
- Subaccount members: additional NOLA users allowed to access the same GHL location.
- Current session user: the exact NOLA user currently inside the iframe.
- Shared subaccount data: records intentionally shared across the GHL location, such as GHL contacts, conversations, credits, sender configuration, and integration status.
- Private user data: records that should be filtered by `user_id`, such as personal tickets, preferences, drafts, private notifications, or future per-user resources.

## Data Model

Keep existing owner document:

```text
location_owners/{locationId}
```

Fields:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "owner_user_id": "nola_user_id",
  "owner_email": "owner@example.com",
  "owner_name": "Owner Name",
  "updated_at": "timestamp"
}
```

Keep or add member documents:

```text
location_owners/{locationId}/members/{userId}
```

Fields:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "user_id": "nola_user_id",
  "email": "member@example.com",
  "email_normalized": "member@example.com",
  "ghl_user_id": "optional_ghl_user_id",
  "role": "member",
  "permissions": ["sms:view", "sms:send"],
  "active": true,
  "source": "register_from_install",
  "updated_at": "timestamp"
}
```

Add a top-level lookup collection for fast autologin resolution:

```text
location_user_links/{linkId}
```

Recommended `linkId`:

```text
{locationId}_{identityType}_{identityHash}
```

Examples:

```text
sxtUvcl8Sm3ki3TRQz3x_ghl_user_abc123
sxtUvcl8Sm3ki3TRQz3x_email_f2a6...
```

Fields:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "user_id": "nola_user_id",
  "identity_type": "ghl_user_id",
  "identity_value_hash": "sha256",
  "ghl_user_id": "optional_ghl_user_id",
  "email_normalized": "member@example.com",
  "active": true,
  "created_at": "timestamp",
  "updated_at": "timestamp"
}
```

Store raw email only where already allowed by current profile patterns. For lookup doc IDs and logs, use hashes to avoid leaking identity in paths.

## Backend Implementation

### 1. Run cross-collection integrity audit

Before repairing individual subaccounts, run the read-only session/install audit across the collections that connect installs to sessions:

```powershell
php scripts/audit_subaccount_session_integrity.php --location=sxtUvcl8Sm3ki3TRQz3x
```

or for a broader scan:

```powershell
php scripts/audit_subaccount_session_integrity.php --limit=500
```

The audit checks:

- `ghl_tokens`
- `integrations`
- `agency_subaccounts`
- `location_owners`
- `location_owners/{locationId}/members`
- `users`

Primary issue codes to watch:

- `MULTIPLE_USERS_NO_DEFAULT_OWNER`
- `DEFAULT_OWNER_USER_MISSING`
- `DEFAULT_OWNER_NOT_ELIGIBLE_FOR_LOCATION`
- `TOKEN_INTEGRATION_COMPANY_MISMATCH`
- `INSTALLED_LOCATION_NO_ELIGIBLE_USER`
- `SINGLE_USER_NO_DEFAULT_OWNER`

The script is read-only. On local machines without the Firestore PHP gRPC extension, run it from a Cloud Run shell, CI job, or admin environment where the existing Firestore client works.

### 2. Immediate repair path for affected locations

Run:

```powershell
php scripts/audit_location_users.php sxtUvcl8Sm3ki3TRQz3x
```

Choose the canonical owner from the audit output.

Then dry-run:

```powershell
php scripts/repair_location_owner.php sxtUvcl8Sm3ki3TRQz3x USER_ID
```

Apply:

```powershell
php scripts/repair_location_owner.php sxtUvcl8Sm3ki3TRQz3x USER_ID --apply
```

This unblocks the current `409` by giving the location a canonical owner/default autologin account. If the product uses shared subaccount login, this is the account returned when the iframe only provides `location_id`.

### 3. Extend autologin request contract

Update `api/auth/ghl_autologin.php` to accept optional user identity:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "company_id": "optional",
  "ghl_user_id": "optional",
  "email": "optional"
}
```

Also accept equivalent query params for GET compatibility:

```text
location_id
company_id
ghl_user_id
email
```

Do not require email if `ghl_user_id` is available.

### 4. Add identity-aware resolver

Create or extend a resolver method:

```php
LocationUserResolver::findForIframeIdentity($db, $locationId, $ghlUserId, $email)
```

Resolution order:

1. If `ghl_user_id` is present, look up active `location_user_links` by `location_id + ghl_user_id`.
2. If no match and email is present, look up active `location_user_links` by `location_id + normalized email`.
3. If no link exists but exactly one eligible user in this location has the same normalized email, create/backfill the link and return that user.
4. If no identity is present, fall back to the admin-selected canonical owner in `location_owners/{locationId}`.
5. If identity is present but maps to multiple users, return `409 DUPLICATE_LOCATION_USER_IDENTITY`.
6. If identity is present but not linked, return `403 LOCATION_USER_NOT_LINKED`.

Do not choose a user by arbitrary query order.

### 5. Mint user-scoped JWTs

For user sessions, JWT claims should include:

```json
{
  "sub": "nola_user_id",
  "role": "user",
  "company_id": "company_id",
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "auth_collection": "users",
  "ghl_user_id": "optional"
}
```

The `sub` claim remains the NOLA user id. `location_id` scopes the session to the active GHL subaccount.

### 6. Add linking/backfill during registration

Update registration/install paths that attach users to a location:

- `api/auth/register_from_install.php`
- helper functions in `api/install_helpers.php`

When a user is attached to a location:

1. Ensure `location_owners/{locationId}` exists for the first/canonical owner.
2. Ensure additional users are written under `location_owners/{locationId}/members/{userId}`.
3. Create or update `location_user_links` for available identities:
   - `location_id + ghl_user_id`
   - `location_id + email_normalized`

### 7. Enforce data visibility

Keep shared location resources scoped by `location_id`:

- GHL contacts
- GHL conversations
- SMS logs
- sender settings
- credit balance
- token/integration status

For private user resources, require both:

```text
location_id == session.location_id
user_id == session.sub
```

Review endpoints that currently list by `location_id` only and decide whether they are shared or private. `api/tickets.php` currently stores `user_id` but lists all tickets for the location. If tickets should be private, filter ticket GET/list/update/delete by `user_id` for normal user sessions, while allowing agency/admin roles to view all location tickets.

### 8. Error contract

Keep existing error:

```text
409 DUPLICATE_LOCATION_USERS
```

Meaning: multiple eligible location users exist, and no valid admin-selected canonical owner/default autologin account is available.

Add:

```text
403 LOCATION_USER_NOT_LINKED
```

Meaning: the current GHL user/email was identified, but that person is not linked to a NOLA user for this subaccount.

Add:

```text
409 DUPLICATE_LOCATION_USER_IDENTITY
```

Meaning: the provided GHL user/email maps to more than one NOLA user in the same location and requires admin repair.

Add response fields:

```json
{
  "requires_login": true,
  "requires_account_link": true,
  "location_id": "sxtUvcl8Sm3ki3TRQz3x"
}
```

where appropriate.

### 9. Observability

Log these fields on autologin failure:

- `location_id`
- `company_id`
- whether `ghl_user_id` was provided
- whether email was provided, but never the raw email in logs unless already standard in current logging policy
- matched user count
- link lookup source
- returned error code

Prefer hashed email in logs.

## Frontend Implementation Dependency

Frontend must attempt to provide current GHL user identity to autologin.

Preferred request:

```ts
await fetch('/api/auth/ghl_autologin', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    location_id: locationId,
    company_id: companyId,
    ghl_user_id: currentGhlUserId,
    email: currentGhlUserEmail
  })
});
```

If GHL user identity is unavailable:

1. Call autologin with `location_id`.
2. Backend should return the admin-selected canonical owner/default autologin account from `location_owners/{locationId}`.
3. If backend returns `DUPLICATE_LOCATION_USERS`, show an admin setup/repair state, not a regular-user account list.
4. Admin chooses the default account in Admin/Settings, which updates `location_owners/{locationId}.owner_user_id`.
5. Retry bootstrap/autologin.

The admin account selector should be implemented in Admin or Agency Settings under subaccount user/profile management. The regular iframe auth/bootstrap layer should only consume the backend-selected session; it must not expose all linked accounts to regular users.

## QA Plan

### Backend tests

1. One location owner only: autologin by `location_id` returns owner token.
2. Multiple users, valid `location_owners`: autologin by `location_id` returns canonical owner token.
3. Multiple users, `ghl_user_id` linked to member: autologin returns that member token.
4. Multiple users, email linked to member: autologin returns that member token.
5. Multiple users, no identity, valid admin-selected owner: autologin returns the selected owner token.
6. Multiple users, no identity and no valid owner: autologin returns `409 DUPLICATE_LOCATION_USERS`.
7. Identity provided but not linked: autologin returns `403 LOCATION_USER_NOT_LINKED`.
8. Identity maps to multiple users: autologin returns `409 DUPLICATE_LOCATION_USER_IDENTITY`.
9. User token for location A cannot access location B unless linked.

### Frontend tests

1. URL/location detection sends the real GHL location id, not company id.
2. Autologin request includes current GHL user identity when available.
3. `DUPLICATE_LOCATION_USERS` displays admin setup/repair state, not a generic broken screen.
4. `LOCATION_USER_NOT_LINKED` displays a link/register flow.
5. Switching GHL users in the same subaccount does not reuse another user's NOLA session.
6. Switching subaccounts clears or replaces stale `nola_auth_token`, `nola_location_id`, and cached profile.
7. Regular iframe users never see a list of all linked NOLA accounts.

## Rollout Plan

1. Repair affected production locations with `scripts/audit_location_users.php` and `scripts/repair_location_owner.php`.
2. Add backend support for identity-aware autologin without removing old `location_id` behavior.
3. Deploy frontend identity forwarding and `409/403` UX handling.
4. Backfill `location_user_links` for existing users from current profile fields and member docs.
5. Enable stricter private-data filtering endpoint by endpoint.
6. Monitor autologin error counts:
   - `DUPLICATE_LOCATION_USERS`
   - `LOCATION_USER_NOT_LINKED`
   - `DUPLICATE_LOCATION_USER_IDENTITY`

## Acceptance Criteria

- Multiple NOLA users can be linked to one GHL subaccount.
- A GHL subaccount with an admin-selected default account opens the iframe and receives that selected NOLA session.
- A user cannot see another user's private records in the same GHL subaccount.
- Shared subaccount resources still load correctly for authorized members.
- `DUPLICATE_LOCATION_USERS` is reduced to a repair-only state, not the normal multi-user path.
