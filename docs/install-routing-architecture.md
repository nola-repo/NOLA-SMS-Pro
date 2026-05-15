# Install Routing Architecture

This document defines deterministic GoHighLevel Marketplace install routing for subaccount installs.

## Entry Points

- `/oauth/callback` -> `ghl_callback.php`
- `/register` -> `install-register.php`
- `/login` -> `install-login.php`
- `/api/auth/register-from-install` -> `api/auth/register_from_install.php`
- `/api/auth/resolve-install-selection` -> `api/auth/resolve_install_selection.php`

Agency installs are separate and enter through `/oauth/agency-callback`.

## Routing Algorithm

1. Exchange the OAuth code.
2. Enter `PENDING_OAUTH`; OAuth permission is not a completed install.
3. Resolve the selected location using only trusted selection signals:
   - signed install selection session
   - explicit `locationId` / `location_id` / selected-location query field
   - OAuth state containing `selected_location_id`
   - `approvedLocations` with exactly one location
   - `locations[]` with exactly one location
4. If exactly one unique exact candidate exists, return `EXACT_SINGLE_LOCATION` and never show the selector.
5. If multiple candidate locations remain and no exact signal chooses one, return `AMBIGUOUS` and show the explicit selection screen.
6. If a company token was returned but no selected location signal or callback candidates exist, return `SELECTION_REQUIRED`, fetch company locations for display only, and show the explicit selection screen.
7. After one selected `locationId` is known, check company ownership against `ghl_tokens/{locationId}`.
8. Save or refresh the location token idempotently with `install_state=PENDING_OAUTH`.
9. Classify install state from token existence plus canonical ownership.
10. Mark `install_state=INSTALLED` only after the location token is persisted and classification is complete.
11. Route:
   - `FRESH_INSTALL` -> `/register?install_token=...`
   - `TOKEN_ONLY` -> `/register?install_token=...`
   - `LINKED_ACCOUNT` -> `/login?welcome_back=1&location_id=...`
   - `AMBIGUOUS` -> selection screen
   - `SELECTION_REQUIRED` -> selection screen populated from company locations
   - `COMPANY_MISMATCH` -> hard-stop error

Registration status is never used as a location selection signal.

## State Machine

| State | Condition | Route |
| --- | --- | --- |
| `PENDING_OAUTH` | OAuth permission granted but location/token/classification is not complete | no final install side effects |
| `FRESH_INSTALL` | no pre-existing `ghl_tokens/{locationId}` and no owner | registration |
| `TOKEN_ONLY` | `ghl_tokens/{locationId}` exists and no canonical owner | registration continuation |
| `LINKED_ACCOUNT` | canonical owner exists | login |
| `AMBIGUOUS` | multiple candidates and no exact selected location | explicit selection |
| `SELECTION_REQUIRED` | OAuth succeeded with a company token/companyId but no exact selected location signal | fetch company locations and require explicit user selection |
| `COMPANY_MISMATCH` | selected location token belongs to another company | hard-stop error |

## Corrected PHP Architecture

`api/install_helpers.php` is the shared routing library. It owns:

- `install_resolve_selected_location()` for trusted location selection
- `install_classify_location()` for state classification
- `install_decide_location_redirect()` for register/login decisions
- `install_claim_owner_lock()` for canonical owner locking
- legacy owner fallback that backfills `location_owners/{locationId}`

`ghl_callback.php` owns OAuth exchange, token persistence, ambiguous selection session creation, and final redirect.

`install-register.php` verifies the signed install token, hydrates the selected location name/id from the selected token doc only, and redirects away from registration when a canonical owner already exists.

`install-login.php` verifies canonical ownership before app handoff. Its create-account path routes token-only locations to registration continuation and blocks linked-owner conflicts.

`api/auth/register_from_install.php` creates or completes the user account, claims `location_owners/{locationId}`, writes user/subaccount metadata, syncs owner metadata to legacy docs, returns a login JWT, and lets the page auto-login.

## Ownership Flow

Canonical ownership source:

```text
location_owners/{locationId}
```

Expected fields:

- `entity_id`
- `owner_user_id`
- `owner_email`
- `owner_name`
- `source`
- `created_at`
- `updated_at`

Alias sources such as `users.active_location_id`, `users.location_id`, `users/{uid}/subaccounts/{locationId}`, `ghl_tokens.owner_*`, and `integrations.owner_*` are migration fallback only. When a fallback finds a valid owner, the helper claims/backfills `location_owners/{locationId}` without overwriting a conflicting canonical owner.

## Callback Flow

Direct location install:

1. Resolve exact selected location.
2. Reject company mismatch before saving over the location token.
3. Save `ghl_tokens/{locationId}` with `install_state=PENDING_OAUTH`.
4. Classify using pre-save token existence plus canonical ownership.
5. Finalize `ghl_tokens/{locationId}` and `integrations/ghl_{locationId}` with `install_state=INSTALLED`.
6. Redirect to registration or login.

Company-scoped token with one exact selected location:

1. Save company token.
2. Stop all bulk logic.
3. Exchange company token only for the selected location token.
4. Save location token with `install_state=PENDING_OAUTH`.
5. Classify, finalize install artifacts, and redirect.

Company-scoped token with multiple candidates and no exact selection:

1. Save company token.
2. Create `install_sessions/{sessionId}` with `type=ambiguous_install_selection`.
3. Sign an `install_selection_session` JWT.
4. Show selection screen.
5. `/api/auth/resolve-install-selection` validates session and selected location, exchanges only that selected location token, saves pending docs, classifies, finalizes, and returns the redirect URL.

Company-scoped token with no exact selection and no usable callback candidates:

1. Save the company token.
2. Create `install_sessions/{sessionId}` with `state=SELECTION_REQUIRED`.
3. Fetch available locations from GHL using `/locations/search?companyId=...` for display only.
4. Show the explicit selection screen.
5. `/api/auth/resolve-install-selection` validates the signed session and exchanges only the selected location token.
6. Save pending docs, classify, finalize, and continue through the normal deterministic register/login routing.
7. Do not create a bulk provisioning session, call `/api/agency/install/provision`, or redirect to `/login?bulk_install=1`.

## Bulk Provisioning Boundary

Normal Marketplace selected subaccount installs are not bulk installs. `/oauth/callback` must never iterate all company locations, create `bulk_install_session`, show provisioning progress, or route to the generic bulk login page.

Bulk provisioning is only allowed outside the normal selected subaccount Marketplace callback, such as:

- agency-wide installs through `/oauth/agency-callback`
- explicit internal admin provisioning tools
- explicit agency provisioning endpoints

The selected subaccount callback may receive a Company-scoped token from GHL, but it must still operate in single-location mode once a selected location signal exists.

## Edge Cases

- Multiple candidates, no exact signal: never choose the unregistered/newest/first location.
- No candidates from the callback but a company token exists: never fail install or guess; fetch company locations and require the user to select one.
- Token exists, no owner: continue registration, not login.
- Owner exists: login, even if stale aliases disagree.
- Canonical owner conflicts with submitted registration email/user id: return ownership conflict.
- Selected location token belongs to another company: return company mismatch.
- Missing or expired install token: registration stops and asks for reinstall.
- Registration form always displays the selected location name and id from the signed token and selected `ghl_tokens/{locationId}` only.
- Repeated callback or repeated selected-location submission uses merge writes and owner locks, so it is idempotent.

## Migration Strategy

1. Deploy the helper changes first so runtime lookups backfill `location_owners/{locationId}` safely.
2. Run a dry inventory of legacy owner aliases:
   - users with `active_location_id`, `location_id`, `locationId`, `ghl_location_id`, `ghlLocationId`
   - `users/{uid}/subaccounts/{locationId}`
   - owner metadata in `ghl_tokens/{locationId}` and `integrations/ghl_{locationId}`
3. Backfill only when there is exactly one owner candidate per location.
4. For conflicts, do not guess. Export the conflicting candidates for manual resolution.
5. After backfill, treat aliases as read-only compatibility fields and keep `location_owners/{locationId}` as the routing authority.
