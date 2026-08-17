# Frontend Handoff: SMS Retry Queue and Sender ID Resiliency

## Summary

The backend now treats provider timeouts from Semaphore and UniSMS as retryable events instead of immediate delivery failures. GHL receives an early HTTP 200 after validation, while NOLA records the message locally and, if the provider times out later, moves the message into `sms_retry_queue` for background retry.

The frontend should use Firestore message fields for display state and optionally read `sms_retry_queue` for admin monitoring. Do not call the retry endpoint directly from a browser; it is protected by `X-Cron-Secret` and is meant for Cloud Scheduler or an authenticated backend admin action.

## Message Document Contract

Documents in `messages/{messageId}` and `sms_logs/{messageId}` now include retry ownership metadata when a timeout is queued:

```ts
interface NolaMessageDoc {
  message_id: string;
  location_id: string;
  number: string;
  message: string;
  direction: 'outbound' | 'inbound';
  sender_id: string;
  sender_name: string;
  status: 'Sending' | 'Pending' | 'Sent' | 'Failed';
  origin:
    | 'ghl_provider_preflight'
    | 'ghl_provider_retry_queued'
    | 'retry_worker_processing'
    | 'retry_worker_success'
    | 'retry_worker_exhausted'
    | 'ghl_provider'
    | 'ghl_provider_failed'
    | string;
  retry_doc_id?: string;
  retry_status?: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  retry_count?: number;
  retry_max_attempts?: number;
  next_retry_at?: FirestoreTimestamp;
  last_retry_at?: FirestoreTimestamp;
  ghl_message_id?: string | null;
  ghl_contact_id?: string | null;
  provider?: 'semaphore' | 'unisms' | 'ghl_provider' | string;
  provider_message_id?: string | null;
  provider_reference_id?: string | null;
  provider_error?: string | null;
  created_at: FirestoreTimestamp;
  updated_at: FirestoreTimestamp;
}
```

Display rule:

```ts
const isRetrying =
  msg.status === 'Pending' &&
  ['pending_retry', 'processing'].includes(msg.retry_status ?? '');

const retryAttemptLabel =
  msg.retry_max_attempts && typeof msg.retry_count === 'number'
    ? `Attempt ${Math.min(msg.retry_count + 1, msg.retry_max_attempts)}/${msg.retry_max_attempts}`
    : null;
```

## Retry Queue Contract

Documents live at `sms_retry_queue/{retryDocId}`. The `retryDocId` is deterministic from the local message ID, so duplicate queue records are avoided for the same GHL/provider message.

```ts
interface SmsRetryQueueDoc {
  retry_doc_id: string;
  message_id: string;
  ghl_message_id: string | null;
  location_id: string;
  phone: string;
  message: string;
  sender_id: string;
  api_key: string | null;
  provider_pref: 'system' | 'semaphore' | 'unisms' | string;
  provider: 'semaphore' | 'unisms' | string;
  account_id: string;
  billing_reference_id: string;
  billing_charged: boolean;
  billing_master_lock: boolean;
  agency_id: string;
  required_credits: number;
  using_free_credits: boolean;
  status: 'pending_retry' | 'processing' | 'completed' | 'exhausted';
  attempts: number;
  max_attempts: number;
  last_error: string | null;
  worker_id?: string | null;
  processing_started_at?: FirestoreTimestamp;
  lease_expires_at?: FirestoreTimestamp | null;
  created_at: FirestoreTimestamp;
  updated_at: FirestoreTimestamp;
  next_retry_at: FirestoreTimestamp;
  completed_at?: FirestoreTimestamp;
  exhausted_at?: FirestoreTimestamp;
  final_provider?: string;
  final_provider_message_id?: string | null;
}
```

State transitions:

```text
pending_retry -> processing -> completed
pending_retry -> processing -> pending_retry
pending_retry -> processing -> exhausted
processing with expired lease -> processing
```

## UI Guidance

Use `status` as the primary user-facing state:

```tsx
function smsStatusLabel(msg: NolaMessageDoc): string {
  if (msg.status === 'Sent') return 'Sent';
  if (msg.status === 'Failed') return 'Failed';
  if (msg.retry_status === 'processing') return 'Retrying...';
  if (msg.retry_status === 'pending_retry') {
    const count = msg.retry_count ?? 0;
    const max = msg.retry_max_attempts ?? 3;
    return `Retrying... Attempt ${Math.min(count + 1, max)}/${max}`;
  }
  return msg.status;
}
```

Recommended badges:

- `Sending`: neutral/in-progress badge.
- `Pending` + `retry_status === 'pending_retry'`: amber badge, "Retrying... Attempt X/3".
- `Pending` + `retry_status === 'processing'`: amber animated badge, "Retrying now...".
- `Sent`: success badge.
- `Failed` + `origin === 'retry_worker_exhausted'`: failed badge with tooltip "Retries exhausted. Credits were restored if billing was charged."

## Admin Retry Queue View

Optional admin table query:

```ts
query(
  collection(db, 'sms_retry_queue'),
  where('status', 'in', ['pending_retry', 'processing']),
  orderBy('next_retry_at', 'asc')
)
```

Suggested columns:

- Recipient
- Sender ID
- Provider
- Status
- Attempts
- Next retry time
- Lease expiry
- Last error
- Message ID

## Backend Guarantees

- Semaphore and UniSMS cURL/HTTP 408 timeouts throw typed `SmsProviderTimeoutException` subclasses.
- Custom Semaphore sender IDs are not swapped to UniSMS defaults during failover.
- `StatusSync` skips retry-worker-owned messages while they are `pending_retry` or `processing`.
- The retry worker claims queue docs before sending and uses a lease to recover stale `processing` jobs.
- Paid credit refunds and free trial usage restoration are idempotent on final exhaustion.
- GHL is synced only on terminal retry outcomes: delivered for `Sent`, failed for `Failed`.

## Deployment Checklist

- Deploy PHP changes.
- Deploy Firestore indexes in `firestore.indexes.json`.
- Confirm Cloud Scheduler job `sms-retry-queue-worker` exists in `asia-southeast1`.
- Confirm it calls `/api/retry_sms_queue.php` every 5 minutes with `X-Cron-Secret`.
- Confirm recent logs show `processed`, `claimed`, `succeeded`, `retried`, or `exhausted` counters from `retry_sms_queue`.
