# Frontend Handoff: User Test Case Gap Review

Date: 2026-06-24

Source: `USER_SIDE_TEST_CASES.csv`

## Purpose

This handoff lists frontend work needed to fully satisfy the user-side test cases. It focuses on missing routes, incomplete UI flows, build risks in this checkout, expected API contracts, and QA coverage.

## Current Frontend Source Risk

The workspace has root-level frontend files such as:

- `App.tsx`
- `Settings.tsx`
- `AuthContext.tsx`
- `authService.ts`
- `account.ts`
- `settingsStorage.ts`
- `hooks/useUserProfile.ts`
- `hooks/useGhlLocation.ts`
- `context/UserProfileContext.tsx`

However, `App.tsx` imports folders that are not present in this checkout:

- `./pages/Dashboard`
- `./pages/GhlCallback`
- `./pages/SharedLogin`
- `./pages/RegisterFromInstall`
- `./components/ProtectedRoute`
- `./utils/safeStorage`
- `./services/authService`

`Settings.tsx` also imports:

- `../utils/pdfGenerator`

That file is not present in this checkout.

Action needed:

- Restore the missing frontend folder structure, or update imports to match the actual source layout.
- Confirm the app builds from this checkout before running the user-side test cases.

## P0 Frontend Gaps

### 1. Forgot Password Route/UI

User test cases:

- `US-AUTH-009`
- `US-AUTH-010`
- `US-AUTH-011`

Backend endpoints exist:

- `/api/auth/forgot_password_otp.php`
- `/api/auth/reset_password_otp.php`

Missing in visible route shell:

- `/forgot-password`

Recommended frontend work:

- Add `/forgot-password` route.
- Build 3-step flow:
  - email submit
  - OTP verify
  - new password submit
- Use generic copy for email submit to avoid account enumeration.
- Handle invalid OTP and expired OTP.
- Redirect to `/login` after successful reset.

### 2. Tickets Page

User test cases:

- `US-TICK-001`
- `US-TICK-002`
- `US-TICK-003`

Current route shell includes `initialView="tickets"`, but no complete ticket API or UI was confirmed.

Recommended frontend work:

- Build a real tickets view after backend endpoint exists.
- Include ticket list, create form, status/history display, loading state, empty state, and error state.
- Scope requests by current `location_id`.

### 3. Buildable Dashboard/Page Structure

User test cases affected:

- Most auth, navigation, dashboard, compose, contacts, templates, tickets, settings, and GHL iframe tests.

Current issue:

- `App.tsx` references missing page/component folders.

Recommended frontend work:

- Restore `pages`, `components`, `utils`, and `services`.
- Confirm these routes load:
  - `/login`
  - `/register-from-install`
  - `/oauth/callback`
  - `/`
  - `/compose`
  - `/contacts`
  - `/templates`
  - `/tickets`
  - `/settings`
  - `/forgot-password`
- Unknown routes should redirect to `/`.

### 4. Billing Report Download

User test case:

- `US-SET-CRD-009`

Current issue:

- `Settings.tsx` calls `generateMonthlyReport(...)`.
- `../utils/pdfGenerator` is missing in this checkout.

Recommended frontend work:

- Restore or rebuild `utils/pdfGenerator`.
- Or switch to a backend report endpoint once available.
- Disable the button only when there are no transactions or data is loading.
- Include selected month, account name, totals, and transaction rows.

## P1 Frontend Gaps And Improvements

### 5. Auth Expiry Handling

User test case:

- `US-SEC-004`

Current visible behavior:

- `AuthContext.tsx` treats a stored token as authenticated.
- URL `?token=` handoff stores the token and cleans the URL.

Recommended frontend work:

- On app boot, call `/api/auth/me.php` or a validation endpoint.
- If token is expired or invalid:
  - clear local/session storage
  - redirect to `/login`
  - avoid rendering protected data first
- Add a shared API wrapper that handles 401 globally.

### 6. Contacts UX

User test cases:

- `US-CONT-001` to `US-CONT-007`

Frontend should support:

- Contact list loading for current location.
- Add contact with phone required.
- Duplicate phone error or merge prompt.
- Search/filter by name, phone, email.
- Use contact in Compose.
- Clear empty, loading, and error states.
- Confirm Location A contacts never appear in Location B.

Backend needs to finish duplicate/search/update/delete support.

### 7. Compose And SMS UX

User test cases:

- `US-COMP-001` to `US-COMP-012`

Frontend should ensure:

- Philippine mobile validation before submit.
- Empty message validation.
- Character and segment count visible.
- Bulk sends use shared `batch_id`.
- Each send includes `Idempotency-Key`.
- Sender dropdown only shows allowed senders.
- Pending/rejected sender IDs are not selectable.
- Insufficient credits show a clear error and do not create a false success state.
- Special characters are preserved in the message body.
- GHL iframe sends include or resolve the correct `location_id`.

### 8. Conversations UX

User test cases:

- `US-CONV-001` to `US-CONV-007`

Frontend should support:

- Conversation list sorted by latest.
- Thread load by `conversation_id`.
- Rename.
- Delete with confirmation.
- Status mapping: `Sending`, `Sent`, `Failed`.
- Bulk conversation per-recipient view without mixing contacts.
- Inbound and outbound messages in one timeline.
- Reply support only if backend/provider path is confirmed; otherwise hide the action.

### 9. Settings UX

User test cases:

- `US-SET-ACC-*`
- `US-SET-SND-*`
- `US-SET-NOT-*`
- `US-SET-CRD-*`

Frontend should ensure:

- Account tab distinguishes standalone vs GHL iframe.
- Standalone valid/invalid Location ID save has clear success/error states.
- GHL iframe Location ID is read-only and auto-detected.
- Connect GHL redirects to OAuth chooser.
- Sender ID request form validates alphanumeric 3-11 characters.
- Approved sender can be set as default.
- Free usage counter updates after default sender sends.
- Notification toggles and threshold persist after refresh.
- Credit refresh does not overwrite last good balance with zero on failure.
- Checkout popup blocked flow is user-friendly.
- Payment success message triggers balance and ledger refresh.

### 10. Navigation And Mobile

User test cases:

- `US-NAV-001` to `US-NAV-006`

Frontend should verify:

- Sidebar/menu items exist: Home, Compose, Contacts, Templates, Tickets, Settings.
- Route changes preserve correct selected view.
- Dark mode persists after refresh.
- Mobile menu opens/closes correctly.
- Refresh while logged in keeps the same route.
- Unknown route redirects to Home.

## API Contract Requirements

Use these headers consistently:

- `Authorization: Bearer <token>`
- `X-GHL-Location-ID: <location_id>` for location-scoped calls
- `Idempotency-Key: <uuid>` for SMS sends
- `X-Request-ID: <uuid>` for all frontend API requests

Important response handling:

- SMS success should use `output.success` and `status`.
- Contacts/templates/conversations generally return `{ success, data }`.
- Billing transaction endpoint returns `{ transactions, total, page, limit }`.
- Credits endpoint may return cached metadata; refresh flows should request fresh data when needed.
- `401` should clear session and redirect to login.
- `403` should show permission denied, not force logout.
- `503` maintenance should show a maintenance state and block send actions.

## Recommended Frontend Test Coverage

Add Playwright or Cypress E2E coverage for:

- Login success, invalid password, blank fields, deactivated user.
- Register-from-install valid, missing token, invalid token.
- Forgot password OTP happy path and invalid OTP.
- Protected route redirect.
- Dark mode desktop and mobile.
- Compose single, invalid number, empty message, long message, bulk send, zero credits.
- Contacts add/search/duplicate/location isolation.
- Templates create/edit/delete/use in compose.
- Tickets create/list/status once backend exists.
- Settings account, sender IDs, notifications, billing, report download.
- GHL iframe token handoff and location auto-detection.
- Expired JWT refresh behavior.

## Frontend Priority Checklist

1. Restore missing `pages`, `components`, `utils`, and `services` or align imports to the actual structure.
2. Add `/forgot-password` route and OTP reset UI.
3. Build or restore tickets UI after backend API exists.
4. Restore or replace `utils/pdfGenerator`.
5. Add global API wrapper for request IDs, auth headers, 401 handling, and location headers.
6. Harden contacts UI around duplicate/search/location scoping.
7. Complete billing UX for auto-recharge, checkout, payment success refresh, and reports.
8. Add E2E tests for the CSV user-side test matrix.

