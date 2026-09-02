# NOLA SMS Pro Release Audit - 2026-06-22

Scope: frontend repo `C:\Users\User\nola-sms-pro` and backend repo `C:\Users\User\nola-sms-pro-backend`.

This pass reviewed User, Agency, Admin, PHP legacy backend, Laravel bridge routes, Firestore rules, auth helpers, SMS/logging paths, billing/subscription endpoints, reporting paths, and realtime listeners. Backend was inspected read-only in this session because the active writable root is the frontend checkout.

## Changes Applied

- Fixed User API authorization header generation in `user/src/utils/authHeaders.ts` so it reuses `getSession()` instead of reading only `sessionStorage`. This keeps API calls aligned with the app's existing token restoration path after reloads, GHL handoff, and new-tab session restore.

## Release Blockers

### Firestore Rules Are Too Broad For Conversation Data

Evidence: `firestore.rules` allows any authenticated Firebase user to read/write all `conversations` and `messages` documents.

Risk: Cross-location data exposure or tampering if a browser client has any Firebase auth session. This conflicts with the backend's location-scoped API model and realtime listener expectations.

Recommended fix: Scope reads/writes by custom claims or move browser writes fully behind backend APIs. At minimum, require document `location_id` membership claims for `conversations` and `messages`; keep backend-only writes for server-owned state.

### `/api/conversations` Rename Path Does Not Persist `name`

Evidence: `api/conversations.php` reads `$name` and returns `Conversation updated`, but the update payload only writes `location_id` and `id`.

Risk: Frontend rename UI can report success while cached/realtime records keep the old name, causing visible mismatch between Sidebar, backend records, and later reloads.

Recommended fix: Add `['path' => 'name', 'value' => $name]` to the update payload, verify ownership before write, and invalidate the location conversation cache after the write.

### Sensitive Backend Logging Still Exists

Evidence: `api/credits.php` logs full request headers and payloads to `credits_debug.log`, `/tmp/credits_debug.log`, and Cloud logs. `api/agency/ghl_sso_decrypt.php` returns `raw => $userData` and logs decrypted identifiers.

Risk: Headers may include secrets/tokens, payloads may include customer or billing details, and decrypted GHL SSO context should not be returned to browsers or retained in logs.

Recommended fix: Replace full payload logging with redacted structured logs using request id, action, amount, location/company id hashes, and failure reason. Remove `raw` from the SSO response and avoid logging decrypted payload content.

### JWT And Webhook-Secret Auth Gates Are Mixed

Evidence: many browser-used user endpoints call `validate_api_request()` first, while frontend code generally sends `Authorization: Bearer ...`; some later call optional JWT location checks, but the initial gate is still `WEBHOOK_SECRET`.

Risk: Contract confusion across User/GHL/browser calls, overuse of shared webhook secrets in browser-adjacent flows, and inconsistent 401/403 behavior.

Recommended fix: Split auth gates by caller type: browser APIs should accept JWT and enforce role/location ownership; internal/webhook APIs should accept `WEBHOOK_SECRET` or provider signatures. Keep compatibility only where explicitly needed.

## High-Priority Cleanup

- Normalize API-base behavior across User, Agency, and Admin. Admin has a direct-base resolver; User and Agency still mix proxy-relative calls with hardcoded `https://smspro-api.nolacrm.io` fallbacks in `user/src/App.tsx`, `user/src/pages/Settings.tsx`, `agency/src/pages/Billing.tsx`, `agency/src/pages/Subaccounts.tsx`, and `agency/src/pages/Subscription.tsx`.
- Reduce five-minute cached `/api/conversations` responses or add stronger invalidation around writes and SMS sends, since User Sidebar also listens realtime and expects backend records to match immediately.
- Review `user/src/api/templates.ts` local mock fallback. It may still be useful for offline dev, but it is technical debt for release builds if the backend endpoint is expected to be canonical.
- Replace scattered bare `console.*` logs with the existing `devLog` helper where logs may include account, location, payload, or customer context.
- Remove or lock down backend debug endpoints/routes before production release: `/api/v2/webhook/debug_ghl`, `/api/v2/webhook/ghl_debug`, direct `debug_*`, `tmp_*`, and test helper scripts.

## Workflow Notes

- GHL embedded launch and auto-login: current code has both User location auto-login and Agency company SSO paths. The main risk is inconsistent storage recovery and hardcoded redirect domains. The User header fix applied in this audit reduces one auth drift path.
- Contacts/conversations/SMS: direct conversation identity now uses location/phone scoped IDs in frontend and backend, but Firestore rules and conversation cache/rename behavior can still create mismatch.
- SMS sending/status sync: backend has idempotency and provider id compatibility fields in `send_sms.php`; live gateway behavior still needs environment verification because PHP is unavailable locally.
- Billing/subscriptions/reports: subscription endpoint returns plan limits and active usage; admin/agency report paths already use wallet transactions and subscription state. Remaining risk is auth gate consistency and hardcoded API bases.
- Realtime updates: Agency subaccounts and User conversations/messages use Firestore listeners. Those listeners depend on secure location-scoped rules and prompt cache invalidation after backend writes.

## Verification

Passed:

- `npx tsc -b` in `user/`
- `npx tsc -b` in `agency/`
- `npx tsc -b` in `admin/`

Blocked or environment-limited:

- `npm run build` in User, Agency, and Admin reaches Vite/esbuild and fails with local Windows `Error: spawn EPERM`.
- Backend PHP lint/PHPUnit were not run because `php` is not available on PATH in this environment.
- Live GHL embedded launch, gateway delivery, webhook callbacks, and Firestore production data validation require deployed credentials/test accounts.