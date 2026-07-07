# NOLA SMS Pro installation lifecycle

This is the canonical contract for agency and sub-account behavior. UI routes
must follow these states instead of independently guessing from individual
Firestore documents.

## Identities

- `companyId` identifies a GHL agency.
- `locationId` identifies one GHL sub-account.
- An agency user may access installed locations belonging to its company.
- A location user may own exactly one location.
- `location_owners/{locationId}` is the canonical location-user ownership lock.

## Lifecycle states

| State | Required records | Allowed next action |
|---|---|---|
| Not installed | No active location token | Start Marketplace installation |
| OAuth pending | Company token exists; location token not ready | Retry location provisioning |
| Registration required | Active location token; no active canonical location user | Complete registration or authenticate as an existing agency user |
| Registered | Active location token and active canonical location user | Autologin, then load app |
| Agency access | Active location token belongs to authenticated agency company | Load app scoped to that location |
| Reconnect required | Installed/registered, but OAuth refresh cannot recover | Reconnect GHL OAuth |
| Cleanup in progress | `cleanup_in_progress=true` | Block product access and retry after cleanup |
| Onboarding expired | `ONBOARDING_EXPIRED` after stale `PENDING_OAUTH` | Restart Marketplace installation |
| Uninstalled | `UNINSTALLED` or `is_live=false` | Reinstall; all messaging remains blocked |

## Invariants

1. Never treat a company ID as a location ID.
2. Never mark a location ready without a Location-scoped OAuth token.
3. Never create a second active owner for a location.
4. Never let a location user access another location.
5. Never infer agency authorization from a location ID alone; require a valid
   agency session whose company owns the location.
6. Stale ownership rows may self-heal only when the previous owner user is gone
   and the lock is not from an in-flight registration.
7. Reinstalling a registered location routes to login, not registration.
8. All product data requests wait until `/api/v2/location/bootstrap` returns
   `next_action=load_app`.
9. Registration must not issue a JWT before the user, owner, token, integration,
   and subaccount activation batch commits successfully.
10. New registration records remain inactive/pending until that activation
    batch succeeds.

## Authoritative runtime gate

`GET /api/v2/location/bootstrap?location_id=...` is the single readiness gate.
Frontend code must follow its `next_action`:

- `run_autologin`
- `complete_registration`
- `show_not_installed`
- `show_reconnect`
- `show_retry`
- `load_app`

Contacts, conversations, templates, billing, and messaging must not load before
`load_app`.

## Required regression scenarios

1. New direct location install and registration.
2. Registered location reinstall routes to login.
3. Agency bulk install provisions each selected location token.
4. Authenticated agency user accesses an installed child location.
5. Location user cannot access a sibling location.
6. Stale owner with deleted user safely recovers.
7. Active owner cannot be replaced.
8. Wrong company/location pairing is rejected.
9. Expired onboarding token returns `INSTALL_TOKEN_EXPIRED`.
10. Uninstall blocks SMS, workflows, conversations, billing, and app bootstrap.
11. Cleanup lock blocks bootstrap before product requests begin.
12. Stale pending onboarding is reported by the dry-run reconciler and becomes
    `ONBOARDING_EXPIRED` only when the reviewed apply mode runs.

## Release gate

Before production deployment:

1. Run `php artisan test` from `laravel/`.
2. Run `php tmp/install_helper_fallback_test.php` from the repository root.
3. Install into one clean test location and confirm the callback records a
   Location-scoped token.
4. Complete registration and confirm bootstrap returns `LOCATION_READY`.
5. Reinstall the same location and confirm it routes to login.
6. Open the location as an authenticated agency user and as its location user.
7. Attempt access from a sibling location and confirm rejection.
8. Uninstall and confirm bootstrap and SMS both reject the location.
9. Run `php scripts/reconcile_pending_installs.php` and review the dry-run
   output before scheduling or using `--apply`.

## Production monitoring

Track these structured codes from bootstrap and autologin logs:

- `LOCATION_INSTALL_PENDING`
- `LOCATION_REGISTRATION_REQUIRED`
- `LOCATION_COMPANY_MISMATCH`
- `LOCATION_SESSION_MISMATCH`
- `GHL_RECONNECT_REQUIRED`
- `INSTALL_TOKEN_EXPIRED`
- `LOCATION_BOOTSTRAP_FAILED`
- `LOCATION_CLEANUP_IN_PROGRESS`
- `LOCATION_ONBOARDING_EXPIRED`

Initial service objectives:

- At least 99% of valid installed-location bootstrap requests reach
  `LOCATION_READY` without manual database repair.
- Fewer than 1% of onboarding submissions expire.
- Zero active-owner replacements and zero cross-company location sessions.
- Any rise in `LOCATION_BOOTSTRAP_FAILED` or `LOCATION_COMPANY_MISMATCH` is
  investigated before the next release.

## Frontend compatibility requirement

The deployed frontend source is not fully present in this repository snapshot.
Its location resolver must accept only explicit location keys (`location_id`,
`locationId`, `ghl_location_id`, `ghlLocationId`, `active_location_id`, and
`activeLocationId`) plus the known `/v2/location/{locationId}/...` path. Generic
`id`, `location`, company, or account values must never become a location ID.

The frontend must call bootstrap first and must not start contacts,
conversations, templates, credits, notifications, or messaging requests until
bootstrap returns `next_action=load_app`.
