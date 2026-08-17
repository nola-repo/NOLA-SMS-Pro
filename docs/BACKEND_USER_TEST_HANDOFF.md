# Backend Handoff: User Test Case Gap Review

Date: 2026-06-24

Source: `USER_SIDE_TEST_CASES.csv`

## Purpose

This handoff lists backend work needed to fully support the user-side test cases. It focuses on missing or partial backend behavior, infrastructure risks, performance bottlenecks, and test setup issues found during the review.

## Current Backend Status

The backend already has substantial support for:

- Auth login, register-from-install, install-token verification, and OTP password reset:
  - `api/auth/login.php`
  - `api/auth/register_from_install.php`
  - `api/auth/verify_install_token.php`
  - `api/auth/forgot_password_otp.php`
  - `api/auth/reset_password_otp.php`
- SMS send, billing deduction, idempotency, sender resolution, delivery status mapping, and GHL sync:
  - `api/webhook/send_sms.php`
  - `api/messages.php`
  - `api/conversations.php`
  - `api/services/CreditManager.php`
  - `api/services/MessageSyncService.php`
- Templates CRUD:
  - `api/templates.php`
- Sender ID requests and default sender config:
  - `api/sender-requests.php`
  - `api/get_sender_config.php`
- Notification preferences:
  - `api/notification-settings.php`
- Credits, wallets, transactions, and agency credit requests:
  - `api/credits.php`
  - `api/billing/subaccount_wallet.php`
  - `api/billing/agency_wallet.php`
  - `api/billing/transactions.php`
  - `api/billing/credit_requests.php`

## P0 Backend Gaps

### 1. Support Tickets Backend Appears Missing

User test cases:

- `US-TICK-001`
- `US-TICK-002`
- `US-TICK-003`

No ticket-specific API endpoint was found. The frontend route/view may exist conceptually, but the backend needs a support ticket resource.

Recommended backend work:

- Add `api/tickets.php` or Laravel v2 route.
- Support `GET`, `POST`, and optionally `PUT` for status updates.
- Scope tickets by authenticated user and `location_id`.
- Include fields: `ticket_id`, `location_id`, `user_id`, `subject`, `message`, `status`, `priority`, `created_at`, `updated_at`.
- Add admin or agency visibility if support staff need to manage tickets.

Acceptance criteria:

- User can create a ticket from the dashboard.
- User can list own tickets.
- User can see ticket status/history.
- Cross-location access returns 403.

### 2. Contact API Is Only Partial

User test cases:

- `US-CONT-001` to `US-CONT-007`

Current endpoint:

- `api/contacts.php`

Current support:

- `GET` list by location.
- `GET` exact phone filter.
- `POST` create contact with required phone.

Missing or partial:

- No duplicate phone guard before creating a contact.
- No general search/filter by name, phone, or email.
- No update endpoint.
- No delete endpoint.
- Phone normalization is not enforced before duplicate checks.

Recommended backend work:

- Add `search` query param for name/email/phone.
- Normalize phone using `PhoneNormalizer`.
- Reject or merge duplicates per location.
- Add `PUT` and `DELETE`.
- Return a consistent response shape: `{ success, data, error }`.

Acceptance criteria:

- Duplicate phone in same location returns 409 or a clear update path.
- Same phone in different locations is allowed.
- Search works across name, email, and phone.
- Unauthorized cross-location contact access returns 403.

### 3. Billing Report Download Is Not Backend-Complete

User test case:

- `US-SET-CRD-009`

Current state:

- `api/billing/transactions.php` returns JSON transaction data.
- No backend PDF/report endpoint was found.
- Frontend references a missing `pdfGenerator` utility in this checkout.

Recommended backend work:

- Add `GET /api/billing/report.php?scope=subaccount&location_id=...&month=YYYY-MM`.
- Generate PDF server-side or return signed CSV/PDF.
- Reuse the same auth checks as `transactions.php`.
- Include ledger rows, totals, balance movement, date range, and account identity.

Acceptance criteria:

- Report downloads for selected month.
- Empty month generates a valid empty report.
- Cross-location report access returns 403.

### 4. Auto-Recharge Has Placeholder Payment Logic

User test cases:

- `US-SET-CRD-003`
- `US-SET-CRD-005`
- `US-SET-CRD-006`

Current state:

- Auto-recharge settings can be saved in wallet endpoints.
- `api/billing/auto_recharge_cron.php` has explicit TODO comments for payment provider charging.
- The cron currently adds credits directly after threshold detection.

Risk:

- This can create credits without confirmed payment.

Recommended backend work:

- Integrate the real payment provider charge or GHL checkout confirmation flow.
- Store payment intent/order ID before crediting.
- Make credit addition idempotent by payment/order reference.
- Add failure state and retry policy.
- Add audit fields: `payment_status`, `payment_reference`, `charged_at`.

Acceptance criteria:

- Auto-recharge only credits after confirmed payment.
- Duplicate webhook/cron retries do not double-credit.
- Failed payment does not alter balance.

## P1 Backend Gaps And Risks

### 5. Auth Expiry And Session Validation

User test case:

- `US-SEC-004`

Backend JWT expiry exists in `api/auth/login.php`, but the frontend can treat any stored token as logged in until an API rejects it. Backend should provide a reliable `/api/auth/me.php` validation contract and consistent 401 behavior.

Recommended backend work:

- Ensure every protected API returns 401 for expired JWT.
- Ensure `/api/auth/me.php` returns a clear `authenticated: false` or 401 on expiry.
- Document frontend behavior: clear session and redirect to `/login`.

### 6. Login Fallback Scans Full Collections

File:

- `api/auth/login.php`

Issue:

- If indexed email lookups fail, login scans all documents in `agency_users` and `users`.

Risk:

- Slow login and Firestore read cost growth.

Recommended backend work:

- Backfill normalized email fields, for example `email_lower`.
- Query only indexed normalized fields.
- Remove or feature-flag full collection fallback after migration.

### 7. Billing Transactions Are Filtered And Paged In PHP

File:

- `api/billing/transactions.php`

Issue:

- The endpoint fetches Firestore docs, filters month in PHP, sorts in PHP, then slices for pagination.

Risk:

- Slow ledger loads for high-volume accounts.
- Inaccurate page counts if future limits are added before filtering.

Recommended backend work:

- Add composite indexes for:
  - `wallet_scope`
  - `account_id`
  - `created_at`
- Apply month range and ordering in Firestore.
- Use cursor pagination where possible.

### 8. Conversation Delete Behavior Is Inconsistent

Files:

- `api/conversations.php`
- `api/messages.php`

Issue:

- `api/conversations.php` deletes the conversation and cascades messages.
- `api/messages.php` deletes only the conversation doc.

Recommended backend work:

- Pick one canonical delete endpoint.
- Ensure all delete paths cascade consistently or mark conversations archived.
- Prefer soft delete if audit/history is required.

## P2 Backend Improvements

- Add rate limiting for login, OTP request, OTP verification, send SMS, and sender ID request endpoints.
- Add OTP attempt counters and lockout window.
- Replace raw `@mail` OTP delivery with configured mail service and observable delivery result.
- Add contract tests for response shapes used by the frontend.
- Add a consistent request ID requirement on all endpoints.
- Confirm CORS allowed origins for whitelabel/custom domains.
- Add a maintenance-mode read endpoint so the frontend can show a controlled maintenance screen.

## Automated Test Infrastructure

Current command:

```powershell
cd laravel
php artisan test
```

Observed result:

- Without `APP_KEY`, Laravel feature tests fail with `MissingAppKeyException`.
- With a temporary `APP_KEY`, tests pass but emit warnings because `laravel/.env` is missing.

Recommended work:

- Add a testing `.env.example` or configure `APP_KEY` inside `phpunit.xml`.
- Ensure bridge tests do not depend on reading a missing `.env`.
- Add backend tests for:
  - contacts duplicate handling
  - tickets CRUD
  - transactions month filtering
  - auto-recharge idempotency
  - report generation
  - expired JWT behavior
  - cross-location 401/403 behavior

## Backend Priority Checklist

1. Build tickets API.
2. Complete contacts CRUD/search/deduplication.
3. Add billing report generation endpoint.
4. Replace auto-recharge TODO with real payment-confirmed crediting.
5. Fix test environment `APP_KEY` and `.env` warnings.
6. Remove login full collection scans after normalized email migration.
7. Move billing transaction filtering/pagination into Firestore queries.
8. Standardize conversation delete behavior.

