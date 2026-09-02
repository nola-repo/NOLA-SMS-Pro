# Backend/Frontend Agency and SMS Audit

Date: 2026-06-19
Repos reviewed:
- Frontend: `C:\Users\User\nola-sms-pro`
- Backend: `C:\Users\User\nola-sms-pro-backend`

## Executive Summary

The current frontend and backend contracts mostly match for agency subscriptions, agency wallet behavior, admin account actions, and SMS sending. The biggest issues found were not payload mismatch problems; they were hardening and freshness problems around SMS duplicate protection, unauthenticated UniSMS status callbacks, provider error/log leakage, and agency wallet fallback refresh behavior.

Changes were made in this pass to harden those areas while keeping existing request/response contracts stable.

## Changes Made

### Backend

- `api/webhook/send_sms.php`
  - Changed the SMS idempotency guard from fail-open to fail-closed.
  - If Firestore duplicate-protection setup fails, the endpoint now returns `503 idempotency_unavailable` instead of sending anyway.
  - This reduces duplicate sends and duplicate billing risk.

- `api/webhook/receive_sms_unisms.php`
  - Requires a valid UniSMS webhook secret before processing status callbacks or inbound messages.
  - Removed raw inbound webhook body logging.
  - Added sanitized webhook metadata logging with hashed sender numbers and `message_present` instead of message content.
  - Removed phone/message content from missing-data and unmapped-sender logs.

- `api/services/ProviderResultService.php`
  - Redacts likely tokens, secrets, API keys, authorization values, and bearer tokens from compacted provider errors before returning/logging them.

### Frontend

- `agency/src/pages/Billing.tsx`
  - Wallet fallback/manual refresh now calls `agency_wallet.php` with `refresh=1`.
  - This avoids stale 60-second cached wallet balances when realtime listeners fail or when the user manually refreshes after direct database/admin balance changes.

## Contract Match Findings

### SMS Send Contract

Frontend matches the current backend hardening handoff:

- Sends `X-GHL-Location-ID` on location-scoped SMS calls.
- Sends `Idempotency-Key` on SMS sends.
- Sends `sendername` when a sender is selected.
- Sends shared `batch_id` for bulk sends.
- Does not enforce a minimum SMS character count; it only blocks empty messages.
- Reads provider success/failure from `status` and `output.success`.
- Handles failed sends as visible failed message rows instead of hiding attempts.

Note: bulk send currently sends one request per unique normalized phone number. If the product needs one request per selected contact even when duplicate phone numbers exist, that is a product-rule change.

### Agency Subscription / Subaccount Limit

Frontend and backend are now aligned with the corrected rule: the plan limit is the number of subaccounts with SMS toggled on, not total installed subaccounts.

- Backend `api/billing/subscription.php` returns `subaccounts_used` and `active_subaccounts` from active/toggled-on subaccounts.
- Backend `api/agency/update_subaccount.php` enforces the subscription limit only when a subaccount is toggled from off to on.
- Frontend `agency/src/utils/subscription.ts` prioritizes `active_subaccounts` / enabled counts over total counts.
- Frontend `agency/src/pages/Subaccounts.tsx` blocks toggling on when the active count reaches the plan limit.
- Frontend `agency/src/pages/Subscription.tsx` labels usage as `Active Subaccounts`, which matches the backend rule.

Known non-security concern: agency subscription pages still include a hardcoded fallback agency ID (`O0YXPGWM9ep2l37dgxAo`). It is useful for local/demo fallback, but production should avoid showing or querying another agency if session resolution fails.

### Agency Wallet

Wallet records are mostly consistent across frontend and backend:

- Backend `api/billing/agency_wallet.php` resolves the current balance through `CreditManager::get_agency_balance()`.
- Admin agency balance edits in `api/admin_sender_requests.php?action=manage_agency` write both `balance` and `credit_balance`, log a `credit_transactions` delta, and invalidate agency wallet/dashboard caches.
- Agency Billing listens directly to Firestore `agency_wallet/{agencyId}` and falls back to `agency_users.company_id == agencyId` for older records.
- Agency Billing fetches transactions from `api/billing/transactions.php?scope=agency`.
- The frontend fallback fetch now bypasses cache with `refresh=1`.

Remaining improvement: direct database changes to old/alternate wallet locations should update the canonical agency wallet document or the UI must keep listening to both canonical and legacy documents. Today the code listens to `agency_wallet/{agencyId}` plus `agency_users` query, but the authoritative balance source is hidden behind `CreditManager`.

### Admin Users Edit Account

Admin Users includes `Edit Account` in the row actions menu. The modal updates name, email, phone, and role through `api/admin_users.php`, and the page polls for updates.

## Vulnerabilities Fixed

1. Duplicate send / duplicate billing risk
   - Cause: `send_sms.php` failed open when idempotency guard setup failed.
   - Fix: fail closed with HTTP 503 before provider send or credit deduction.

2. Unauthenticated UniSMS status mutation
   - Cause: status callbacks could update existing outbound message records without a valid webhook secret if they matched a known UniSMS reference.
   - Fix: validate webhook secret before any status or inbound processing.

3. PII in webhook logs
   - Cause: raw UniSMS webhook payloads and some edge-case logs could include phone numbers and message content.
   - Fix: log sanitized metadata and hashed sender numbers only.

4. Provider secret leakage in error text
   - Cause: provider error strings could include tokens or authorization values and then be returned/logged through the normal failure path.
   - Fix: redact likely secret patterns in `ProviderResultService::compactGatewayError()`.

## Remaining Risks / Improvements

- Ensure production has `UNISMS_WEBHOOK_SECRET` configured. After this pass, missing webhook secret config returns a server misconfiguration error instead of accepting callbacks.
- Consider rate limiting public webhook endpoints, especially SMS send and provider callback routes.
- Consider replacing the hardcoded agency fallback ID in agency pages with a clear session error state for production builds.
- Consider making `CreditManager` expose an explicit canonical agency wallet document contract so frontend, admin, reports, and direct DB test instructions all point to one source of truth.
- Consider adding backend tests for idempotency failure, UniSMS unauthorized callback rejection, provider-error redaction, and active-subaccount limit enforcement.
- Consider adding a small frontend test around Billing fallback refresh to ensure `refresh=1` stays on the wallet GET.

## How To Test Without Buying

### Agency Subscription Limit

1. Pick an agency/company ID.
2. In Firestore, update its agency user/subscription record:
   - `subscription.plan = starter`
   - `subscription.status = active`
   - `subscription.subaccount_limit = 1`
3. In `agency_subaccounts`, set exactly one subaccount for that agency to `toggle_enabled = true`.
4. Set another subaccount to `toggle_enabled = false`.
5. Open Agency > Subscription and confirm it shows `1 / 1 Active Subaccounts`.
6. Open Agency > Subaccounts and try toggling the off subaccount on. It should be blocked by the UI and also rejected by backend if called directly.
7. Change `subscription.subaccount_limit = 5`, refresh, and confirm the same toggle can now be enabled.

### Agency Wallet Realtime / Fallback

1. Open Agency > Credits & Billing.
2. Directly edit `agency_wallet/{agencyId}.balance` in Firestore.
3. Confirm the displayed balance updates via realtime listener.
4. If realtime is unavailable, click the refresh button. The request should include `refresh=1` and should fetch a fresh backend balance.
5. In Admin > All Agencies, edit the agency balance. Confirm a credit transaction is created and the agency Billing balance updates without a full page reload.

### SMS Hardening

1. Send a short non-empty SMS such as `Hi`; frontend should allow it.
2. Confirm the SMS request includes `Idempotency-Key`, `X-GHL-Location-ID`, selected `sendername`, and `batch_id` for bulk sends.
3. Call UniSMS webhook without the configured secret; it should return 401 and should not update message status.
4. Call UniSMS webhook with the configured secret; valid callbacks should process normally.