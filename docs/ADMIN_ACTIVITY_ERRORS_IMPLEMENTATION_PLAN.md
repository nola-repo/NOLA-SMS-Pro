# Admin Platform Activity Error Reduction Implementation Plan

Date prepared: August 12, 2026  
Target work date: August 13, 2026  
Scope: Backend + frontend changes for Admin Platform Activity error clarity, SMS provider failure reduction, and handoff-ready diagnostics.

## 1. Current Findings

The Admin Platform Activity page is not showing only backend/platform crashes. It is a unified activity feed containing SMS sends, SMS history logs, sender ID requests, and credit transactions.

Recent sampled activity showed:

- 507 simulated unique activity feed rows.
- 54 failed rows.
- 2 pending rows.
- 201 successful rows.
- 250 credit transaction rows that do not map cleanly to success/failure status.

Failure categories from the sampled feed:

- 43 Semaphore timeout failures.
- 5 invalid phone failures.
- 2 UniSMS content rejected as spam-like.
- 1 UniSMS timeout.
- 1 UniSMS content too short.
- 2 other stored failure reasons.

Main conclusion: most visible "errors" are SMS provider delivery or validation issues, not admin platform crashes.

## 2. Goals

1. Reduce real SMS send failures, especially Semaphore timeout failures.
2. Stop labeling all failed activity as generic platform errors.
3. Normalize backend activity rows so frontend can display clear categories.
4. Make Admin Platform Activity useful for triage: provider issue, validation issue, content issue, sender request, credit event, or true platform error.
5. Provide testable acceptance criteria for backend and frontend teams.

## 3. Backend Implementation Plan

### Phase 1: Normalize Activity Row Metadata

Add normalized fields to SMS-related activity rows written into `messages` and `sms_logs`.

Recommended fields:

```json
{
  "status_group": "successful | pending | failed | validation | provider_error",
  "error_category": "semaphore_timeout | unisms_timeout | invalid_phone | content_rejected | content_too_short | provider_validation_422 | ghl_sync_error | platform_exception | other",
  "severity": "info | warning | error",
  "is_platform_error": false,
  "is_retryable": true,
  "retry_count": 0,
  "last_retry_at": null,
  "finalized_at": null
}
```

Initial mapping:

- `sent`, `delivered`, `completed`, `success` -> `status_group=successful`, `severity=info`.
- `queued`, `sending`, `pending`, `provider_pending` -> `status_group=pending`, `severity=info`.
- Invalid phone -> `status_group=validation`, `error_category=invalid_phone`, `severity=warning`, `is_platform_error=false`.
- Provider timeout -> `status_group=provider_error`, `error_category=semaphore_timeout` or `unisms_timeout`, `severity=warning`, `is_platform_error=false`, `is_retryable=true`.
- UniSMS HTTP 422 content issues -> `status_group=validation`, `error_category=content_rejected` or `content_too_short`, `severity=warning`.
- Unhandled backend exceptions -> `status_group=failed`, `error_category=platform_exception`, `severity=error`, `is_platform_error=true`.

Primary files:

- `api/webhook/send_sms.php`
- `api/webhook/ghl_provider.php`
- `api/services/StatusSync.php`
- `api/services/providers/SemaphoreProvider.php`
- `api/services/providers/UniSmsProvider.php`
- `api/admin_sender_requests.php`

### Phase 2: Add Error Categorization Helper

Create a shared backend helper for consistent categorization.

Suggested file:

- `api/services/ActivityErrorClassifier.php`

Responsibilities:

- Accept raw provider response, status, curl error, HTTP code, and validation reason.
- Return normalized metadata fields.
- Keep the exact provider error in `raw_error` or existing provider response fields for detail modals.
- Avoid duplicating string matching across `send_sms.php`, `ghl_provider.php`, and `StatusSync.php`.

Suggested public methods:

```php
ActivityErrorClassifier::fromProviderFailure(string $provider, ?int $httpCode, ?string $error, array $providerResponse = []): array;
ActivityErrorClassifier::fromValidationFailure(string $reason): array;
ActivityErrorClassifier::fromStatus(string $status): array;
```

### Phase 3: Improve Semaphore Timeout Handling

Most observed failures are Semaphore timeouts. Treat timeouts as provider uncertainty first, not immediate permanent failure.

Implementation steps:

1. When Semaphore send times out, store status as `provider_pending` or `queued_timeout_unconfirmed` if the provider may still have accepted the request.
2. Add `is_retryable=true` and `error_category=semaphore_timeout`.
3. Let status reconciliation finalize the message as `sent`, `failed`, or `expired`.
4. Add circuit breaker logic:
   - Track recent Semaphore timeout rate.
   - If threshold is exceeded, temporarily route eligible sends to UniSMS.
   - Store `provider_failover_used=true` when failover happens.
5. Add structured logs for every provider timeout and failover decision.

Acceptance criteria:

- A Semaphore timeout does not always become a permanent `failed` row immediately.
- Activity feed displays the row as provider issue or pending retry.
- Status sync can later change it to successful.
- Admin cache is invalidated after status changes.

### Phase 4: Pre-Validate Phone Numbers

Stop calling providers for invalid recipient numbers.

Implementation steps:

1. Add or reuse a phone normalizer for PH mobile numbers.
2. Validate workflow/GHL phone numbers before provider send.
3. If invalid:
   - Do not call Semaphore or UniSMS.
   - Save activity row with `status_group=validation`, `error_category=invalid_phone`.
   - Return a clear API response.
4. Include sanitized `location_id`, `source`, and `workflow_block_reason` fields for triage.

Acceptance criteria:

- Invalid numbers show as validation issues, not provider failures.
- Invalid numbers do not consume provider calls or credits.
- GHL workflow caller receives a clear response.

### Phase 5: Pre-Validate SMS Content

Reduce UniSMS HTTP 422 failures.

Implementation steps:

1. Reject empty, placeholder-only, or too-short messages before provider send.
2. Preserve existing GSM-7 sanitization.
3. Map UniSMS 422 responses into clear categories:
   - Spam-like content -> `content_rejected`.
   - Minimum length issue -> `content_too_short`.
   - Other 422 -> `provider_validation_422`.
4. Store a user-friendly `failure_summary`.

Acceptance criteria:

- UniSMS 422 rows have readable categories.
- Frontend does not need to parse raw provider JSON.
- Raw provider response remains available for diagnostics.

### Phase 6: Update Admin Activity Endpoint

Update `api/admin_sender_requests.php?action=logs` to return normalized summary and metadata.

Recommended response shape:

```json
{
  "status": "success",
  "summary": {
    "total": 507,
    "successful": 201,
    "pending": 2,
    "failed": 54,
    "provider_errors": 44,
    "validation_errors": 6,
    "platform_errors": 0
  },
  "data": []
}
```

Implementation steps:

1. Keep existing data array for backward compatibility.
2. Add `summary` object.
3. Add normalized metadata to each returned row.
4. Keep deduplication by document ID.
5. Add category counts server-side so frontend does not infer too much.
6. Invalidate `admin_activity_logs_all` after status updates, retries, sender request changes, and credit transaction changes.

Acceptance criteria:

- Existing frontend still works if it ignores `summary`.
- New frontend can use `summary` and normalized row fields.
- Activity feed no longer needs to parse provider error strings to categorize failures.

### Phase 7: Backend Tests

Add focused tests for:

- Semaphore timeout classification.
- UniSMS timeout classification.
- UniSMS HTTP 422 content classification.
- Invalid phone pre-validation.
- Status sync updating a provider timeout from pending to sent.
- Admin activity summary counts.
- Cache invalidation after status updates.

Suggested test locations:

- `laravel/tests/Unit/`
- `laravel/tests/Feature/Bridge/`

## 4. Frontend Implementation Plan

### Phase 1: Update Terminology

Avoid presenting all failures as platform errors.

Recommended labels:

- Replace "Review" with "Needs Review" or split into "Pending" and "Failed".
- Use "Delivery Issues" for provider failures.
- Use "Validation Issues" for invalid phone/content problems.
- Reserve "Platform Errors" only for `is_platform_error=true`.

Primary frontend file:

- `tmp/nola-sms-pro-frontend/admin/src/pages/components/SystemSettings.tsx`

### Phase 2: Use Backend Summary

When `?action=logs` returns `summary`, use it for dashboard cards.

Cards to show:

- Events
- SMS
- Credits
- Sender Requests
- Provider Issues
- Validation Issues
- Platform Errors

Fallback:

- If `summary` is missing, keep current client-side count logic.

### Phase 3: Add Category Filters

Add filters/chips:

- All
- SMS
- Credits
- Sender Requests
- Provider Timeout
- Invalid Phone
- Content Rejected
- Platform Error

Filtering should use backend fields:

- `status_group`
- `error_category`
- `severity`
- `is_platform_error`

Do not parse raw provider response in the frontend except as a fallback.

### Phase 4: Improve Row Copy

Display user-friendly failure messages.

Suggested mappings:

- `semaphore_timeout`: "Semaphore timeout. Message may need retry or provider failover."
- `unisms_timeout`: "UniSMS timeout. Message may need retry."
- `invalid_phone`: "Invalid recipient phone number."
- `content_rejected`: "Provider rejected the SMS content."
- `content_too_short`: "Message content is too short."
- `platform_exception`: "Backend platform error. Engineering review required."

In the detail modal:

- Show friendly summary first.
- Show raw provider response under "Technical Details".
- Keep copy button for reference ID, provider message ID, and location ID.

### Phase 5: Add Provider Health Strip

Add a compact status strip above the activity table:

- Semaphore timeouts today.
- UniSMS timeouts today.
- Invalid phone count today.
- Last successful send.
- Failover active or inactive.

This can be backed by the Admin Activity summary first, then upgraded to a dedicated health endpoint later.

### Phase 6: Frontend Tests

Add tests for:

- Summary cards render from backend `summary`.
- Old response shape still works.
- Category filters isolate provider timeout, validation, and platform error rows.
- Friendly message mapping works.
- Raw provider response appears only in detail modal.

## 5. Suggested Work Order Tomorrow

1. Backend: add `ActivityErrorClassifier`.
2. Backend: update phone/content validation before provider calls.
3. Backend: update provider failure handling and normalized metadata writes.
4. Backend: update Admin Activity endpoint summary.
5. Backend: add cache invalidation after status updates.
6. Frontend: consume normalized fields and summary.
7. Frontend: update labels, filters, and friendly failure messages.
8. QA: run seeded/fake failure cases plus one real read-only activity analysis.

## 6. QA Checklist

Backend:

- Invalid phone does not hit SMS provider.
- Invalid phone does not deduct credits.
- Semaphore timeout is classified as `semaphore_timeout`.
- UniSMS timeout is classified as `unisms_timeout`.
- UniSMS spam-like rejection is classified as `content_rejected`.
- Admin Activity returns `summary`.
- Admin Activity cache clears after status changes.
- True exceptions are the only rows with `is_platform_error=true`.

Frontend:

- Admin page does not call provider failures "platform errors".
- Summary cards match backend summary.
- Filters work by category.
- Detail modal shows friendly reason and raw diagnostics.
- Existing old logs without normalized fields still render safely.

## 7. Deployment Notes

- Deploy backend first.
- Frontend must tolerate both old and new response shapes.
- Keep raw provider error fields for support/debugging.
- After deploy, rerun the read-only analyzer and compare:
  - Semaphore timeout count.
  - Invalid phone count.
  - Provider validation count.
  - Platform error count.

## 8. Open Questions

1. Should Semaphore timeouts be retried automatically, or marked pending for scheduler reconciliation first?
2. Should failover from Semaphore to UniSMS be automatic, admin-configurable, or manual only?
3. Should invalid GHL workflow phone numbers create visible Admin Activity rows, or be hidden from default activity and shown only under Validation Issues?
4. What timeout threshold should trigger provider health warnings?
5. Should credit deduction happen only after provider acceptance, or remain as-is with refund/reversal on final failure?
