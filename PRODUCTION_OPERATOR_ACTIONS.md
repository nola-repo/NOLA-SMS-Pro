# Production Operator Actions

These items complete the production-hardening code paths that require cloud-side configuration.

## Firestore TTL

Enable TTL for:

- Collection: `idempotency_keys`
- Field: `expires_at`

Recommended optional TTLs for new queue/lock collections:

- Collection: `ghl_sync_jobs`
- Field: `expires_at`

Firestore TTL deletion is asynchronous, so expired documents may remain visible for some time after expiry.

## Cloud Scheduler

Create protected scheduler calls using the existing `CRON_SECRET` value.

Dashboard aggregate refresh:

- Method: `GET`
- Path: `/api/admin_health_stats_cron.php`
- Header: `X-Cron-Secret: <CRON_SECRET>`
- Schedule: every 5 minutes

GHL sync queue worker:

- Method: `GET`
- Path: `/api/webhook/process_ghl_sync_jobs.php?limit=20`
- Header: `X-Cron-Secret: <CRON_SECRET>`
- Schedule: every 1 minute

## Query-String Webhook Secret Migration

`validate_api_request()` now logs `Deprecated query-string webhook secret used` when a caller authenticates with `?secret=` or `?token=`.

Migration sequence:

1. Deploy the logging change.
2. Watch Cloud Logging for the deprecation log.
3. Move any remaining callers to `X-Webhook-Secret`.
4. Rotate `WEBHOOK_SECRET`.
5. Remove the query-string fallback from `api/auth_helpers.php`.
