# Backend Hardening Frontend Handoff

Date: 2026-06-19

## Summary

This backend pass centralizes shared SMS hardening behavior without changing the existing frontend request contracts.

No frontend payload changes are required for the current implementation.

## What Changed

- Philippine mobile normalization now flows through `api/services/PhoneNormalizer.php`.
- Firestore-safe SMS message document IDs now flow through `api/services/FirestoreId.php`.
- Provider send result/failure handling now flows through `api/services/ProviderResultService.php`.
- `api/webhook/send_sms.php`, `api/webhook/ghl_provider.php`, and `api/services/MessageSyncService.php` now reuse those services.
- The backend no longer blocks UniSMS messages for being under 10 characters.
- `api/ghl_contacts.php` now keeps a longer last-good contacts cache. If GHL has a temporary outage or transient token-refresh failure, the backend can return the last synced contacts with `stale: true` instead of immediately failing the UI with `503`.

## Frontend Requirements

No immediate frontend changes are required.

Continue sending:

- `X-GHL-Location-ID` on location-scoped calls.
- `Idempotency-Key` on SMS send calls.
- `sendername` when a sender is selected.
- `batch_id` for bulk sends.

## Frontend Validation Note

No minimum SMS character count is required on the frontend. If UniSMS rejects a short message at the provider level, the backend will return it through the normal provider failure path.

## Contract Notes

- SMS success should still be determined from `output.success` / `status` in the response.
- Failed provider sends may still create local failed message rows so the UI can show a failed delivery instead of hiding the attempt.
- Bulk sends remain one request per recipient from the frontend, with a shared `batch_id`; backend duplicate protection should continue to treat each recipient send as its own operation.
- Contact fetches may include `stale: true` and `warning` when the backend is showing last synced GHL contacts during a temporary GHL/token issue.

## Backend Files Touched

- `api/services/FirestoreId.php`
- `api/services/PhoneNormalizer.php`
- `api/services/ProviderResultService.php`
- `api/webhook/send_sms.php`
- `api/webhook/ghl_provider.php`
- `api/services/MessageSyncService.php`
- `api/ghl_contacts.php`
- `laravel/tests/Unit/BackendHardeningServicesTest.php`
