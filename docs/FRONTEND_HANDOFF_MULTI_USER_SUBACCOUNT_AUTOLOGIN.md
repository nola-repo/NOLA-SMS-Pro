# Frontend Handoff: Multi-User Subaccount Autologin

Date: 2026-06-29

## Summary

The backend allows multiple NOLA users to be linked to the same GHL subaccount. The current `DUPLICATE_LOCATION_USERS` error does not mean multi-user is forbidden.

It means frontend/backend autologin only identified the GHL subaccount, and backend did not have a valid admin-selected default account to return safely.

To support this safely, the admin must be able to choose which NOLA account is the default autologin account for each GHL subaccount. Regular iframe users must not see a list of all linked accounts.

## Current Failing Example

```json
{
  "error": "Multiple users are linked to this location.",
  "code": "DUPLICATE_LOCATION_USERS",
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "repair_hint": "Run scripts/audit_location_users.php for this location, then set the canonical owner in location_owners."
}
```

User-facing interpretation:

```text
This subaccount has multiple linked NOLA users, but no default autologin account has been selected.
```

Do not show this as "subaccount not installed" or "registration required."

## Frontend Goal

When the app loads inside the GHL iframe, resolve:

```text
location_id
company_id, if available
current GHL user id, if available
current GHL user email, if available
```

Then call:

```text
POST /api/auth/ghl_autologin
```

with as much identity context as possible.

The normal iframe should not expose linked account lists. If the backend returns an admin-selected default account, save that session and continue. If the backend cannot pick an account, show a setup/repair state that tells the agency/admin to choose the default autologin account in Admin/Settings.

## Required Autologin Payload

Preferred:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "company_id": "optional_company_id",
  "ghl_user_id": "current_ghl_user_id",
  "email": "current.user@example.com"
}
```

Minimum existing behavior:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x"
}
```

Minimum behavior is still supported, but it cannot always select the correct user when multiple NOLA users belong to the same GHL location.

## Location Detection Rules

Continue the prior location-id hardening:

- Prefer explicit GHL location fields only:
  - `location_id`
  - `locationId`
  - `ghl_location_id`
  - `ghlLocationId`
  - `active_location_id`
  - `activeLocationId`
- Accept path-style GHL URLs like:

```text
/v2/location/{locationId}/custom-page-link/{pageId}
```

- Do not use generic `id` as the location id.
- Do not use company/account ids as location ids.
- Do not use numeric-only ids as GHL location ids unless backend has already confirmed them as installed locations.
- Current GHL context must win over stale local storage.

## Current User Identity Detection

Inspect the iframe/bootstrap context for the current GHL user identity.

Candidate field names to support if present:

```text
user_id
userId
ghl_user_id
ghlUserId
currentUser.id
user.id
staff.id
```

Candidate email fields:

```text
email
user.email
currentUser.email
staff.email
```

Important:

- Validate message origins where possible.
- Log the source path used for identity resolution in development/debug mode.
- Do not treat a contact id as a user id.
- Do not treat a location id as a user id.
- Do not persist one GHL user's identity across another GHL user switch.

## Recommended Frontend Flow

1. Load app shell.
2. Resolve current GHL `location_id`.
3. Resolve current GHL user identity if available.
4. Clear stale NOLA session if stored `nola_location_id` conflicts with current GHL `location_id`.
5. Call location bootstrap, if already implemented.
6. If bootstrap returns `GHL_AUTOLOGIN_REQUIRED`, call autologin with identity context.
7. Save returned token using existing session storage.
8. Refetch `/api/auth/me`.
9. Load the app only after the profile matches the current `location_id`.

Pseudo-code:

```ts
const context = await resolveGhlIframeContext();

const payload = {
  location_id: context.locationId,
  company_id: context.companyId,
  ghl_user_id: context.userId,
  email: context.email,
};

const res = await fetch('/api/auth/ghl_autologin', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify(payload),
});

const json = await res.json();

if (res.ok) {
  saveSession(json);
  await refreshProfile();
  return;
}

handleAutologinError(json);
```

## Admin-Selected Autologin Account

Do not implement a regular-user account switcher that lists all linked NOLA accounts. That would expose who registered or has access to the subaccount.

Instead, implement an admin-only account mapping control in the admin/settings surface for subaccount user management.

Recommended location:

```text
Admin or Agency Settings -> Subaccount Users / Profile Access
```

The admin control should allow an authorized admin to:

- view users linked to the current GHL subaccount
- choose the default/canonical autologin account
- change the default when ownership changes
- add or remove linked members
- repair duplicate or stale mappings

When the admin chooses an account, backend should update:

```text
location_owners/{locationId}.owner_user_id
```

and related owner profile fields such as owner email/name. After that, `/api/auth/ghl_autologin` can return the chosen account for that GHL location.

The iframe auth/bootstrap layer still needs to handle this before protected app routes render:

- detects the current GHL `location_id`
- resolves iframe/user context
- calls `/api/location/bootstrap.php`
- calls `/api/auth/ghl_autologin`
- saves `nola_auth_token`
- retries `/api/auth/me`

But the iframe layer should only consume the backend decision. It should not show all available accounts to regular users.

Trigger the admin setup/repair state when:

- backend returns `409 DUPLICATE_LOCATION_USERS`
- backend returns a future `DEFAULT_AUTOLOGIN_ACCOUNT_REQUIRED`
- stored NOLA session belongs to a different `location_id` than the current iframe context and autologin cannot replace it

## Error Handling Contract

### `409 DUPLICATE_LOCATION_USERS`

Meaning:

```text
Multiple users are linked to the GHL location, and no valid admin-selected default account is available.
```

Frontend action:

- Do not show a linked-account list to the regular iframe user.
- Show an admin setup/repair message.
- Tell the admin/agency owner to choose the default autologin account in Admin/Settings.
- Do not show "not installed."
- Do not keep retrying autologin in a loop.
- Include support/debug details:

```text
location_id
error code
request id if available
```

### `403 LOCATION_USER_NOT_LINKED`

Meaning:

```text
The frontend identified the current GHL user/email, but that person is not linked to a NOLA user for this subaccount.
```

Frontend action:

- Show "link your NOLA account" or "complete registration" flow.
- After successful login/registration, bind the NOLA user to the current location.
- Retry bootstrap/autologin.

### `409 DUPLICATE_LOCATION_USER_IDENTITY`

Meaning:

```text
The provided current-user identity maps to more than one NOLA user in this location.
```

Frontend action:

- Show support-required state.
- Do not choose one locally.

### `401 GHL_AUTOLOGIN_REQUIRED`

Meaning:

```text
A location-scoped NOLA session is missing or stale.
```

Frontend action:

- Run autologin once for the current GHL context.
- Save returned session.
- Retry profile/bootstrap.

### `403 LOCATION_SESSION_MISMATCH`

Meaning:

```text
Stored NOLA session belongs to a different GHL location.
```

Frontend action:

- Clear stale token/profile/location storage.
- Run autologin for the current location.

## Admin Setup Fallback

If GHL does not provide current user id/email, the backend should use the admin-selected default account for this subaccount.

Frontend should show an admin setup/repair state when backend returns `DUPLICATE_LOCATION_USERS`.

Future admin endpoint:

```text
POST /api/admin/location_owner
```

Expected future payload:

```json
{
  "location_id": "sxtUvcl8Sm3ki3TRQz3x",
  "owner_user_id": "chosen_nola_user_id"
}
```

Only admin/agency-owner roles should be allowed to call this endpoint. Regular iframe users should never receive the list of linked account emails/user IDs unless they have admin permission.

## Session Storage Requirements

When autologin succeeds, save:

- `nola_auth_token`
- `nola_auth_role`
- `nola_company_id`
- `nola_location_id`
- `nola_auth_user`
- `nola_user`

When current GHL location changes:

- Clear stale token if it belongs to a different location.
- Clear cached profile.
- Run autologin again.

When current GHL user changes inside the same location:

- Clear token/profile unless the session profile belongs to the same NOLA user.
- Run identity-aware autologin again.

## UI Requirements

Do not display technical backend wording directly.

Recommended messages:

For `DUPLICATE_LOCATION_USERS`:

```text
Ask your admin to choose the default NOLA SMS Pro account for this subaccount.
```

For `LOCATION_USER_NOT_LINKED`:

```text
Your GHL user is not linked to a NOLA SMS Pro account for this subaccount.
```

For `DUPLICATE_LOCATION_USER_IDENTITY`:

```text
We found more than one matching NOLA account. Please contact support.
```

Include a retry button only when retry can change the result. Do not retry indefinitely.

## QA Checklist

- One GHL subaccount with one NOLA user opens automatically.
- One GHL subaccount with multiple NOLA users opens the correct account when `ghl_user_id` is available.
- Same subaccount opens the correct account when only email is available.
- Same subaccount shows admin setup/repair state when no default autologin account is configured.
- Switching between two GHL users in the same subaccount does not reuse the previous user's NOLA session.
- Switching between GHL subaccounts clears stale location/session state.
- Numeric company/account id is never sent as `location_id`.
- `DUPLICATE_LOCATION_USERS` does not show install/registration-required copy.
- `DUPLICATE_LOCATION_USERS` does not expose a list of linked NOLA accounts to regular iframe users.
- `/api/auth/me` is retried after successful autologin.

## Backend References

Current backend files:

- `api/auth/ghl_autologin.php`
- `api/services/LocationUserResolver.php`
- `api/auth/me.php`
- `api/auth_helpers.php`
- `scripts/audit_location_users.php`
- `scripts/repair_location_owner.php`

Related implementation plan:

- `MULTI_USER_SUBACCOUNT_AUTOLOGIN_IMPLEMENTATION_PLAN.md`
