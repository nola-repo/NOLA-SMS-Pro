# Install Routing Architecture

This document defines the expected GHL Marketplace install routing for subaccount users.

## Entry Points

- `/oauth/callback` -> `ghl_callback.php`
- `/register` -> `install-register.php`
- `/login` -> `install-login.php`
- `/api/auth/register-from-install` -> `api/auth/register_from_install.php`

Agency installs are separate and enter through `/oauth/agency-callback`.

## Required Outcomes

Every subaccount install callback must end in exactly one of these outcomes:

- Selected subaccount is already linked to a user -> `/login?welcome_back=1&location_id=...`
- Selected subaccount has a token but no linked user -> `/register?install_token=...`
- Selected subaccount has never been installed -> `/register?install_token=...`
- Selected subaccount cannot be determined -> bulk/ambiguous login banner or explicit error, never a guessed registration form
- Selected subaccount belongs to a different company -> explicit mismatch error

## Selected Subaccount Resolution

The callback only treats a location as selected when one trusted signal resolves to one location:

- `locations[]` contains exactly one location
- `approvedLocations` contains exactly one location
- direct `locationId` / `location_id` / selected-location query field agrees with candidate locations
- OAuth state matches a known candidate location
- the full provisioned candidate set contains exactly one location

Important rule: registration status is not a selection signal. If multiple locations were provisioned and only one is unregistered, that alone must not route to registration.

## Registered Detection

The shared source of truth is `install_classify_location()` in `api/install_helpers.php`.

It checks:

- `ghl_tokens/{locationId}` existence
- company mismatch against the token document
- `location_owners/{locationId}`
- user root aliases: `active_location_id`, `location_id`, `locationId`, `ghl_location_id`, `ghlLocationId`
- `users/{uid}/subaccounts/{locationId}` and location field aliases
- owner metadata fallbacks in `ghl_tokens/{locationId}` and `integrations/ghl_{locationId}`

When an older record is found, the helper backfills `location_owners/{locationId}` so the next reinstall uses the fast canonical path.

## Form Guarding

The register form and register POST both re-check ownership. This means manual navigation to `/register?install_token=...` cannot create a second owner for a linked subaccount.

The login form also verifies that the authenticated account is linked to the requested `location_id` before handing off to the app.
