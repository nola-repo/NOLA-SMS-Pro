# Admin Platform Activity Frontend Implementation Plan

Date revised: August 13, 2026  
Primary owner: Frontend  
Backend status: Done, pushed, and deployed  
Goal: Update Admin Platform Activity so provider, validation, and true platform issues are shown clearly instead of being grouped as generic "errors."

## 1. Backend Changes Already Deployed

The backend baseline was implemented and deployed on August 12, 2026.

- Commit: `d8d03a5 Normalize admin activity error metadata`
- Cloud Run revision: `sms-api-00928-jfc`
- Cloud Build: `b30aa236-e57e-4a9c-9faa-72bb7ec4c62f`
- Endpoint affected: `/api/admin_sender_requests.php?action=logs`

The backend change is backward-compatible. Existing frontend code can keep reading `data`; the new fields are additive.

### New Top-Level Response Field

`?action=logs` now returns a `summary` object alongside the existing `data` array.

Example:

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
    "platform_errors": 0,
    "warnings": 50,
    "errors": 0
  },
  "data": []
}
```

### New Row Fields

New and backfilled activity rows may include:

```json
{
  "status_group": "successful | pending | failed | validation | provider_error | other",
  "error_category": "semaphore_timeout | unisms_timeout | invalid_phone | content_rejected | content_too_short | provider_validation_422 | ghl_sync_error | platform_exception | other",
  "severity": "info | warning | error",
  "is_platform_error": false,
  "is_retryable": true,
  "failure_summary": "Semaphore timeout. Message may need retry or provider failover."
}
```

### Backend Meaning

- `provider_error`: SMS provider issue, not an admin platform crash.
- `validation`: invalid phone/content issue, not an admin platform crash.
- `failed`: final failed state or true failure category.
- `is_platform_error=true`: only use this for actual backend/platform issues.
- `failure_summary`: preferred user-facing reason when available.

## 2. Frontend Problem To Solve

The current Admin Platform Activity UI can make provider delivery failures look like admin platform errors. This causes confusion because most logged "errors" are actually:

- Semaphore timeout delivery issues.
- Invalid phone number blocks.
- UniSMS content validation rejections.
- Sender request rejections or pending reviews.
- Credit events that are activity, not errors.

Frontend should use backend categories to make the page feel like a triage dashboard, not an alarming error dump.

## 3. Frontend Goals

1. Stop labeling all failed rows as generic platform errors.
2. Use backend `summary` for the activity stats when present.
3. Display provider issues, validation issues, and platform errors as separate concepts.
4. Add filters for the new backend categories.
5. Show friendly failure text first, with raw provider details only in the detail modal.
6. Keep compatibility with older backend responses and older log rows.

## 4. Primary Frontend File

Main file to update:

- `tmp/nola-sms-pro-frontend/admin/src/pages/components/SystemSettings.tsx`

Current areas to inspect:

- `fetchLogs`
- `getType`
- `getStatusGroup`
- `activityStats`
- filter controls
- activity row renderer
- detail modal

## 5. Implementation Plan

### Phase 1: Consume Backend Summary

Update `fetchLogs` to store both:

- `logsData.data`
- `logsData.summary`

Recommended state:

```ts
type ActivitySummary = {
  total: number;
  successful: number;
  pending: number;
  failed: number;
  provider_errors: number;
  validation_errors: number;
  platform_errors: number;
  warnings: number;
  errors: number;
};

const [activitySummary, setActivitySummary] = useState<ActivitySummary | null>(null);
```

Fallback behavior:

- If `summary` exists, use it for top cards.
- If `summary` is missing, keep the existing client-side count logic.

Acceptance criteria:

- Old response shape still renders.
- New response shape renders with backend counts.
- No runtime error if `summary` is missing or incomplete.

### Phase 2: Replace Generic "Review" Count

Current "Review" grouping is too vague because it combines failed and pending activity.

Recommended cards:

- Events
- SMS
- Credits
- Sender Requests
- Provider Issues
- Validation Issues
- Platform Errors

If there is limited space, use:

- Events
- Provider Issues
- Validation Issues
- Platform Errors
- Pending

Important display rules:

- `provider_errors` should not be styled as a critical platform outage by default.
- `validation_errors` should use warning styling, not danger styling.
- `platform_errors` should be the only card with strong error styling.

Acceptance criteria:

- Semaphore timeout rows increase Provider Issues, not Platform Errors.
- Invalid phone rows increase Validation Issues.
- `platform_errors` remains 0 unless backend sends `is_platform_error=true`.

### Phase 3: Add Category-Aware Helpers

Create helpers that prefer backend fields but gracefully fall back to old logic.

Suggested helpers:

```ts
const getStatusGroup = (log: any) => {
  if (log.status_group) return String(log.status_group).toLowerCase();

  const status = String(log.status || log.delivery_status || '').toLowerCase();
  if (['sent', 'delivered', 'approved', 'completed', 'paid', 'success', 'successful'].includes(status)) return 'successful';
  if (['pending', 'queued', 'processing', 'requested', 'sending'].includes(status)) return 'pending';
  if (['failed', 'rejected', 'revoked', 'error', 'denied'].includes(status)) return 'failed';
  return status ? 'pending' : 'successful';
};

const getErrorCategory = (log: any) => {
  return String(log.error_category || '').toLowerCase();
};

const isPlatformError = (log: any) => {
  return Boolean(log.is_platform_error);
};
```

Acceptance criteria:

- Old logs without `status_group` still render.
- New logs use backend classification.
- Raw provider strings are no longer the primary categorization path.

### Phase 4: Add Filters

Add filters/chips for:

- All
- SMS
- Credits
- Sender Requests
- Provider Timeout
- Invalid Phone
- Content Rejected
- Validation
- Platform Error

Filter behavior:

- Provider Timeout: `error_category` is `semaphore_timeout` or `unisms_timeout`.
- Invalid Phone: `error_category=invalid_phone`.
- Content Rejected: `error_category=content_rejected`, `content_too_short`, or `provider_validation_422`.
- Validation: `status_group=validation`.
- Platform Error: `is_platform_error=true`.

Acceptance criteria:

- Provider timeout filter isolates Semaphore/UniSMS timeout rows.
- Invalid phone filter isolates invalid-phone blocks.
- Platform Error filter does not include provider failures.

### Phase 5: Improve Row Labels And Copy

Use `failure_summary` as the preferred display text when present.

Recommended mapping fallback:

```ts
const friendlyFailureReason = (log: any) => {
  if (log.failure_summary) return log.failure_summary;

  switch (log.error_category) {
    case 'semaphore_timeout':
      return 'Semaphore timeout. Message may need retry or provider failover.';
    case 'unisms_timeout':
      return 'UniSMS timeout. Message may need retry.';
    case 'invalid_phone':
      return 'Invalid recipient phone number.';
    case 'content_rejected':
      return 'Provider rejected the SMS content.';
    case 'content_too_short':
      return 'Message content is too short.';
    case 'provider_validation_422':
      return 'Provider rejected the SMS request as invalid.';
    case 'platform_exception':
      return 'Backend platform error. Engineering review required.';
    default:
      return getFailureReason(log);
  }
};
```

Recommended row titles:

- `provider_error` + timeout category: "Provider Timeout"
- `validation` + invalid phone: "Invalid Phone"
- `validation` + content category: "Content Validation"
- `is_platform_error=true`: "Platform Error"
- sender request rejected: "Sender Request Rejected"
- pending sender request: "Sender Request Pending"

Acceptance criteria:

- Activity rows are understandable without opening the modal.
- Provider timeout rows do not say "Platform Error."
- Raw provider text is still available in detail view.

### Phase 6: Update Detail Modal

Detail modal should show:

- Friendly summary
- Category
- Severity
- Retryable: yes/no
- Provider
- Status
- Location ID
- Reference ID
- Provider message/reference ID
- Raw provider response under "Technical Details"

Do not remove raw provider data; support still needs it.

Acceptance criteria:

- Support can copy IDs from modal.
- Non-technical admin users see a readable reason first.
- Raw provider response is not the first or only explanation.

### Phase 7: Add Provider Health Strip

Add a compact strip above the table or under the stats cards.

Suggested items:

- Semaphore timeouts
- UniSMS timeouts
- Invalid phone issues
- Content validation issues
- Platform errors

This can initially be calculated client-side from loaded logs. Later it can move to a dedicated backend health endpoint.

Acceptance criteria:

- Admin can quickly see whether the issue is provider health, input validation, or platform error.
- Strip does not block existing activity list workflow.

## 6. UX Copy Guidelines

Use calm and specific labels:

- "Provider issue" instead of "System error"
- "Validation issue" instead of "Failed"
- "Timeout" instead of raw cURL text
- "Platform error" only when `is_platform_error=true`

Avoid showing raw text like this as the main row reason:

```txt
Semaphore cURL error: Connection timed out after 8068 milliseconds
```

Prefer:

```txt
Semaphore timeout. Message may need retry or provider failover.
```

## 7. Frontend QA Checklist

Use mocked rows or a staging response containing:

- `status_group=provider_error`, `error_category=semaphore_timeout`
- `status_group=provider_error`, `error_category=unisms_timeout`
- `status_group=validation`, `error_category=invalid_phone`
- `status_group=validation`, `error_category=content_rejected`
- `status_group=failed`, `error_category=platform_exception`, `is_platform_error=true`
- old row with only `status=failed` and no normalized fields
- credit transaction row
- sender request row

Checklist:

- Summary cards render from backend `summary`.
- Page still works if `summary` is absent.
- Provider timeout rows are not counted as Platform Errors.
- Invalid phone rows appear as Validation Issues.
- Platform Error filter only shows `is_platform_error=true`.
- Friendly reason appears in row and modal.
- Raw provider response appears in modal technical details.
- Existing search and pagination still work.
- Existing type pills still work for SMS, Credits, and Sender Requests.

## 8. Backend Follow-Up Items

These are not required for the frontend work but remain useful backend hardening tasks:

1. Decide whether Semaphore timeouts should be retried automatically or marked pending for scheduler reconciliation.
2. Decide whether failover from Semaphore to UniSMS should be automatic, admin-configurable, or manual.
3. Decide whether validation issues should appear in default activity or only under filters.
4. Add deeper classifier unit tests for edge cases.
5. Consider a dedicated provider health endpoint if the Activity page needs longer time windows than the current feed.

## 9. Deployment Order

1. Backend: done and deployed.
2. Frontend: update Admin Platform Activity to consume the new fields.
3. QA frontend against production/staging API response shape.
4. Deploy frontend.
5. Recheck Admin Platform Activity counts and labels after deploy.

## 10. Success Definition

This work is complete when Admin Platform Activity clearly answers:

- Is this a provider issue?
- Is this a validation/input issue?
- Is this a sender request or credit event?
- Is this a true backend/platform error?

Provider failures should no longer make the admin platform look broken unless the backend explicitly marks `is_platform_error=true`.
