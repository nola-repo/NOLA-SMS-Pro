# Backend Console Error Cleanup Implementation Plan

Date: 2026-06-22

Source: `docs/CONSOLE_ERROR_CLEANUP_HANDOFF.md`

## Scope

This plan covers only backend work. The frontend location-session gate, quiet retry behavior, debug-log cleanup, and request-ID client wrapper are being handled separately.

Backend goals:

- Preserve strict tenant and location authorization.
- Return stable, machine-readable error codes for expected failure states.
- Make location auto-login deterministic when multiple user records reference a location.
- Repair existing ambiguous location ownership data without destructive automatic merges.
- Prevent conversation serialization from turning mixed timestamp data into HTTP 500 responses.
- Verify agency owners can access only locations belonging to their company while normal users remain bound to their assigned location.

## Repository Findings

- Location authorization is centralized in `api/auth_helpers.php` through `auth_assert_ghl_api_location_allowed(...)`.
- `api/ghl_contacts.php` and `api/notifications.php` already call the shared authorization helper.
- `api/conversations.php` validates only `WEBHOOK_SECRET`; it does not load optional JWT context or apply the shared location authorization check.
- `api/conversations.php` calls `formatAsString()` directly on `last_message_at` and `updated_at`, so strings, `DateTimeInterface`, null-like values, or malformed historical values can cause a 500.
- `api/auth/ghl_autologin.php` queries `users` by several location fields and returns 409 as soon as any one query finds two matches.
- The current install flow intentionally supports a canonical owner plus additional location members through `location_owners/{locationId}` and its `members` subcollection. `api/auth/register_from_install.php` explicitly allows additional users for the same subaccount.
- Therefore, enforcing one active user document per location would conflict with the current membership model. The safer design is one canonical auto-login owner per location, with zero or more active members who must authenticate as themselves.

## Contract Decisions

Before implementation, treat these as the backend contract:

1. A location may have multiple active user accounts, but exactly one canonical owner is eligible for location-only silent auto-login.
2. `location_owners/{locationId}` is the canonical ownership record. `users` location fields are compatibility and audit data, not a uniqueness mechanism.
3. A normal-user JWT can access only the location represented by its profile's `active_location_id` and `ghl_token_ref`.
4. An agency JWT can access a location only when `auth_location_belongs_to_company(...)` proves the company relationship.
5. Existing HTTP status meanings remain stable; new `code` values augment responses rather than replacing `error` messages.

If the product requirement is truly one account total per location, pause before Phase 3 and redesign/remove the existing additional-member flow. Applying that uniqueness rule directly would be a breaking behavior change.

## Error Response Contract

Add a small shared JSON error responder in `api/auth_helpers.php`, or a narrowly scoped helper beside it, and use the following stable codes:

| HTTP | Code | Meaning |
| --- | --- | --- |
| 400 | `INVALID_TOKEN_REFERENCE` | Profile contains an invalid `ghl_token_ref`. |
| 403 | `LOCATION_SESSION_MISMATCH` | Normal user's requested location differs from `active_location_id`. |
| 403 | `LOCATION_NOT_AUTHORIZED` | Location is not assigned to the user or does not belong to the agency company. |
| 403 | `PROFILE_LOCATION_CONFLICT` | `active_location_id` and `ghl_token_ref` disagree. |
| 403 | `LOCATION_INACTIVE` | Installation exists but is inactive or uninstalled. |
| 404 | `LOCATION_NOT_INSTALLED` | No token or integration exists for the location. |
| 404 | `LOCATION_USER_NOT_FOUND` | No canonical location user can be resolved. |
| 409 | `DUPLICATE_LOCATION_USERS` | Ownership remains ambiguous and needs support repair. |
| 401/403 | `TOKEN_RECONNECT_REQUIRED` | Use only when the token provider positively classifies a permanent OAuth/reconnect failure. |

Responses should keep the current human-readable field and add `code`, for example:

```json
{
  "error": "Location does not match your active_location_id.",
  "code": "LOCATION_SESSION_MISMATCH"
}
```

Do not expose exception messages, token contents, document IDs unrelated to the caller, or internal Firestore details.

## Implementation Phases

### Phase 1: Structured Authorization Errors

Target files:

- `api/auth_helpers.php`
- `api/auth/ghl_autologin.php`
- `api/agency/ghl_autologin.php` where equivalent states are returned

Work:

1. Introduce one response helper that sets JSON content type, HTTP status, `error`, and `code`, then exits.
2. Update every rejection branch in `auth_assert_ghl_api_location_allowed(...)` with a stable code.
3. Keep `LOCATION_SESSION_MISMATCH` specific to a normal user's `active_location_id` mismatch. Use `LOCATION_NOT_AUTHORIZED` for agency/company membership failures and other cross-tenant requests.
4. Add codes to installed, inactive, missing-user, and duplicate-user responses in location auto-login.
5. Preserve existing status codes and message strings during the first rollout to avoid breaking clients that still match text.

Acceptance criteria:

- Contacts and notifications mismatch responses contain `code: LOCATION_SESSION_MISMATCH`.
- A normal user cannot request a different location.
- An agency owner can access a proven child location but not another company's location.
- Webhook-secret-only callers retain current behavior when no JWT is supplied.

### Phase 2: Conversation Endpoint Hardening

Target files:

- New `api/services/ApiValueFormatter.php` or an equivalent shared utility
- `api/conversations.php`
- `laravel/tests/Unit/BackendHardeningServicesTest.php`

Work:

1. Add a side-effect-free timestamp formatter supporting:
   - `Google\Cloud\Core\Timestamp`
   - `DateTimeInterface`
   - objects exposing a safe `get()` or `formatAsString()` result
   - non-empty strings
   - integer/float Unix timestamps if historical data contains them
   - null and unsupported values, returned as null
2. Replace direct `formatAsString()` calls for `last_message_at` and `updated_at`.
3. Load `auth_get_optional_jwt_context($db)` after Firestore initialization and call `auth_assert_ghl_api_location_allowed(...)` before cache lookup or Firestore reads/writes.
4. Preserve webhook-secret-only compatibility when JWT context is absent.
5. Keep auth/location failures as controlled 4xx responses; do not let the outer catch convert them to 500.
6. Log unexpected failures server-side and return a generic 500 response without `$e->getMessage()`.

Acceptance criteria:

- Conversations serialize Timestamp, DateTime, string, numeric, null, and unsupported timestamp fixtures without throwing.
- Unauthorized JWT requests fail before cache or Firestore data access.
- Valid agency and normal-user requests follow the same location rules as contacts and notifications.
- Existing trusted webhook-secret callers still work without JWT.

### Phase 3: Deterministic Auto-Login Ownership

Target files:

- `api/auth/ghl_autologin.php`
- `api/install_helpers.php`
- `api/auth/register.php`
- `api/auth/register_from_install.php`

Work:

1. Change location auto-login resolution order:
   - Read `location_owners/{locationId}`.
   - Validate its `owner_user_id` points to an existing, active, non-agency user whose normalized location fields include the requested location.
   - If valid, use that canonical user even when active members also reference the location.
   - Only use legacy `users` field queries as a fallback for locations without a canonical owner record.
2. In the legacy fallback, collect and deduplicate matches by user document ID across all supported fields before deciding the result. The current per-field early return can miss cross-field ambiguity.
3. If exactly one valid fallback match exists, backfill `location_owners/{locationId}` and continue.
4. If multiple valid fallback matches exist and no owner can be proven, return 409 with `DUPLICATE_LOCATION_USERS`; do not choose by query order or timestamps.
5. Ensure all location-level registration paths claim or validate the canonical owner record before completing. Additional users should be written as members, not rejected as duplicates.
6. Add the same ownership call to plain `api/auth/register.php`, which currently writes location fields without using the ownership model. Alternatively, reject location-linked registration there and require the install registration flow; choose one public entry path and document it.
7. Use Firestore document creation/transaction semantics for owner claims so concurrent registrations cannot silently replace the canonical owner.

Acceptance criteria:

- Multiple legitimate members no longer cause silent auto-login 409 when a valid canonical owner exists.
- Ambiguous legacy locations still return a deterministic 409 until repaired.
- Concurrent registration cannot overwrite an existing canonical owner.
- Inactive, agency-role, missing, or wrong-location owner records are never used for user auto-login.

### Phase 4: Audit and Repair Tool

Target files:

- New `scripts/audit_location_users.php`
- New `scripts/repair_location_users.php`, or one command with explicit `--apply`
- `docs/` runbook describing backup, dry-run, apply, and verification

Work:

1. Make dry-run the default and require both an explicit location ID and `--apply` for mutations.
2. Audit all supported location references: `active_location_id`, `location_id`, `ghl_location_id`, and `ghl_token_ref`.
3. Report user ID, normalized email, role, active state, company, token reference, install/source metadata, and canonical owner/member state.
4. Classify findings as:
   - healthy canonical owner
   - valid additional member
   - missing owner record
   - stale owner record
   - conflicting location fields
   - ambiguous legacy owners
   - inactive duplicate
5. Allow an operator to nominate the canonical owner explicitly. Do not automatically select based only on newest timestamp or token.
6. On apply, update the canonical user's location fields and `ghl_token_ref`, repair `location_owners`, preserve legitimate members, and deactivate or unlink only records explicitly selected by the operator.
7. Record repair metadata such as operator, timestamp, reason, previous values, and request/change ticket ID in an audit collection or append-only repair record.

Acceptance criteria:

- Dry-run performs no writes.
- Re-running an applied repair is idempotent.
- Every mutation has an audit record and a documented rollback value.
- Repaired locations pass auto-login, normal-user isolation, and agency-access tests.

### Phase 5: Request-ID Log Correlation

Target files:

- `api/logger.php`
- `api/cors.php` only if header handling needs adjustment
- Cloud Run/Apache logging configuration as applicable

Work:

1. Prefer a valid inbound `X-Request-ID`; otherwise accept `X-Correlation-ID`; otherwise generate a server ID.
2. Sanitize and length-limit inbound IDs before logging or echoing them.
3. Return the chosen value in `X-Request-ID` and use the same value in application logs.
4. Verify the Apache/Cloud Run access-log configuration reads the same header. CORS already allows and exposes both headers.
5. Do not trust request IDs for authorization, deduplication, or idempotency.

Acceptance criteria:

- One request has the same ID in response headers, application logs, and access logs.
- Requests without a client ID receive a generated ID.
- Oversized or malformed IDs are replaced, not logged verbatim.

## Test Plan

### Unit Tests

Add focused tests for:

- Every mixed timestamp input and unsupported-object fallback.
- Error-code mapping for all authorization branches.
- Location matching across `active_location_id`, legacy fields, and `ghl_token_ref`.
- Canonical-owner validation and deduplication of the same user found through multiple fields.
- Request-ID validation, fallback, and generation.

Prefer extracting pure decision/formatting functions into `api/services/` so they can be tested without bootstrapping endpoint scripts that call `exit`.

### Integration/Feature Tests

Cover these scenarios against a Firestore emulator or a controlled test project:

1. Normal user, assigned location: contacts, notifications, and conversations return success.
2. Normal user, different location: all three return 403 plus `LOCATION_SESSION_MISMATCH`.
3. Agency user, child location: all three return success.
4. Agency user, unrelated location: all three return 403 plus `LOCATION_NOT_AUTHORIZED`.
5. Canonical owner plus two members: location auto-login selects only the canonical owner.
6. Two legacy users and no owner record: auto-login returns 409 plus `DUPLICATE_LOCATION_USERS`.
7. Mixed historical conversation timestamps: response stays 200 and invalid values serialize as null.
8. Webhook-secret-only conversation caller: remains compatible.

The Laravel bridge tests verify forwarding only; they are not sufficient for this logic. Add unit coverage for extracted legacy services and a small endpoint integration harness for response status/body behavior.

## Rollout Order

1. Deploy structured error codes and conversation timestamp/auth hardening.
2. Run the location-user audit in dry-run mode and review all ambiguous locations.
3. Deploy canonical-owner auto-login resolution and registration guardrails.
4. Repair known ambiguous beta locations in small batches, verifying auto-login after each batch.
5. Enable end-to-end request-ID correlation and verify it in Cloud Run logs.
6. Run the beta readiness matrix for normal users and agency owners.

## Monitoring and Rollback

Monitor by endpoint, status, and error code:

- Count of `LOCATION_SESSION_MISMATCH` should fall after the frontend gate rollout.
- `DUPLICATE_LOCATION_USERS` should trend to zero as repairs complete.
- Conversation 500s should fall to zero for serialization/auth sequencing cases.
- Any increase in `LOCATION_NOT_AUTHORIZED` for valid agency locations indicates company-location mapping data needs investigation; do not loosen authorization as a workaround.

Rollback boundaries:

- Error-code additions are backward compatible and can remain during rollback.
- Timestamp formatting can be reverted independently.
- Canonical-owner resolution should be feature-flagged or isolated so legacy resolution can be restored without reverting repaired data.
- Repair operations require recorded previous values; rollback must be an explicit audited repair, not a bulk blind restore.

## Definition of Done

- Strict authorization remains enforced across contacts, notifications, and conversations.
- All expected authorization and install states return documented machine-readable codes.
- Conversation timestamp variants cannot cause a 500.
- Auto-login resolves a canonical owner deterministically and never chooses an arbitrary duplicate.
- Existing ambiguous locations have been audited and repaired through an idempotent, auditable process.
- Registration paths cannot create or replace canonical ownership accidentally.
- Backend and access logs correlate requests using the frontend-provided request ID.
- The normal-user and agency-owner integration matrix passes before broad beta testing.
