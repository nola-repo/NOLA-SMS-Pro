# Single-User Location Model

Location-level NOLA accounts use a strict one-to-one relationship:

```text
location_owners/{locationId}.owner_user_id -> users/{userId}
```

## Rules

- A GHL location has exactly one canonical NOLA user.
- Autologin always resolves the canonical owner. GHL staff identity does not
  select a different NOLA account.
- Reinstallation is allowed only for the existing canonical user.
- A second account receives `409 LOCATION_ALREADY_REGISTERED`.
- One NOLA location user cannot own a second GHL location.
- `location_owners/{locationId}/members` and `location_user_links` are legacy
  data and are not used by runtime registration or autologin.
- Agency accounts and their access to multiple subaccounts remain unchanged.

## Existing Data Migration

Audit first:

```powershell
php scripts/enforce_single_location_user.php LOCATION_ID OWNER_USER_ID
```

Apply only after confirming the canonical owner:

```powershell
php scripts/enforce_single_location_user.php LOCATION_ID OWNER_USER_ID --apply
```

The migration removes legacy member and identity-link rows and deactivates
non-canonical user records linked to that location. It never deletes user
documents.

For a database-wide audit and repair:

```powershell
php scripts/enforce_single_user_all_locations.php
php scripts/enforce_single_user_all_locations.php --apply
```
