# Manual Smoke Test Checklist

Use real test accounts, a safe test phone number, and a known test GoHighLevel location/company. Record the browser, viewport, account, and API environment used.

## Access And Sessions

- [ ] User login succeeds with a valid user account.
- [ ] Agency login succeeds with a valid agency account.
- [ ] Admin login succeeds with a valid admin account.
- [ ] Expired user session redirects or recovers without exposing sensitive error details.
- [ ] Expired agency session redirects or recovers without exposing sensitive error details.
- [ ] Expired admin session clears admin auth and returns to admin login.
- [ ] Browser refresh on protected user pages preserves or restores the session correctly.
- [ ] Browser refresh on protected agency pages preserves or restores the session correctly.
- [ ] Browser refresh on protected admin pages preserves or restores the session correctly.

## SMS Sending

- [ ] Single SMS send succeeds, creates one conversation row, and shows the sent message in Compose.
- [ ] Bulk SMS send succeeds for multiple unique contacts and shows a clear sent/failed/skipped summary.
- [ ] Bulk SMS duplicate numbers are skipped or handled according to product rules.
- [ ] Failed SMS path shows a user-safe failure reason and does not reveal provider secrets, raw payloads, headers, or stack traces.
- [ ] Very long SMS text shows correct segment/credit estimate and does not break the composer layout.
- [ ] Very long recipient names and phone labels truncate or wrap cleanly.

## Contacts And Templates

- [ ] Contact creation succeeds with name and phone number.
- [ ] Contact creation validation handles missing, invalid, or duplicate phone numbers cleanly.
- [ ] Template creation succeeds with valid name/content.
- [ ] Template validation handles unsupported placeholders with clear user-safe copy.
- [ ] Template insertion into Compose works.

## Billing, Reports, And Requests

- [ ] User report download opens the report modal and downloads a non-empty PDF when events exist.
- [ ] Empty report periods show `No Events To Download` or equivalent disabled state.
- [ ] Sender request approval works from admin and updates the user sender ID state.
- [ ] Sender request rejection shows the customer-facing reason only.
- [ ] Credit request flow works from user to agency/admin review path.
- [ ] Credit approval/rejection updates balances and transaction/report history.

## GoHighLevel States

- [ ] GHL connected state shows the connected location/company and enables GHL-backed workflows.
- [ ] GHL disconnected state shows reconnect guidance and keeps non-GHL-safe areas usable.
- [ ] GHL token expired state prompts reconnect without leaking token refresh details.
- [ ] Embedded user launch resolves the correct `location_id` and does not show another subaccount's data.
- [ ] Embedded agency launch resolves the agency/company ID and does not get overwritten by subaccount messages.

## Admin System Health

- [ ] System Health shows database status, active gateway, SMS success rate, failed sends, pending queue, and billing health.
- [ ] System Health no longer duplicates the Platform Activity table.
- [ ] Diagnostic Console lines are useful for troubleshooting and redact sensitive values.
- [ ] Failure Triage shows latest failure context without stack traces or secrets.
- [ ] Low Credit Watch shows accounts at or below the warning threshold.

## Responsiveness And Themes

- [ ] User app works at 390px, 768px, and desktop widths.
- [ ] Agency app works at 390px, 768px, and desktop widths.
- [ ] Admin app works at 390px, 768px, and desktop widths.
- [ ] Mobile menus open, close, and do not crop dropdowns or modals.
- [ ] Light mode has readable text, icons, tables, modals, and empty states.
- [ ] Dark mode has readable text, icons, tables, modals, and empty states.
- [ ] Logo sizing is consistent in sidebars, headers, and login screens.
- [ ] Favicon displays in each app tab.
- [ ] Page titles match the active page.

## Loading, Empty, And Slow Network

- [ ] Empty conversations state is clear and not debug-like.
- [ ] Empty contacts state is clear and not debug-like.
- [ ] Empty templates state is clear and not debug-like.
- [ ] Empty reports/transactions state is clear and not debug-like.
- [ ] Slow network shows skeletons/spinners without layout jumps.
- [ ] Slow API failures show user-safe messages and retry paths where available.

## Production Cleanliness

- [ ] No visible placeholder copy remains in normal production flows.
- [ ] No visible debug-looking labels remain except the admin Diagnostic Console.
- [ ] Browser console is quiet in production for normal login/navigation/send/report flows.
- [ ] No sensitive error details show to users.