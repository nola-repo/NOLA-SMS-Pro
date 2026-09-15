# Timeout and Cost Cleanup Report

Date: 2026-09-15
Project: `nola-sms-pro`
Scope: GCP cost cleanup, Redis/VPC removal, admin Activity and Logs Explorer timeout investigation.

## Summary

The high-cost Redis/VPC path has been disconnected from the live Cloud Run service.

Verified live Cloud Run service `sms-api`:

- Current serving revision: `sms-api-01003-q5c`
- Memory: `1Gi`
- Minimum instances: `0`
- Maximum instances: `5`
- VPC connector annotation: absent
- `REDIS_HOST`, `REDIS_PORT`, and `REDIS_PASSWORD`: absent

Verified GCP network/cache state:

- VPC connector `nola-vpc-connector`: deleted
- Memorystore Redis instance `nola-redis`: still exists and is now orphaned
- Redis instance details: Basic tier, 1 GiB, `asia-southeast1-a`, host `10.42.1.243`, state `READY`

## Root Cause of Admin Timeout Window

During the cleanup sequence, older Cloud Run revisions still had Redis env vars while the VPC connector had already been deleted. Those revisions attempted to reach private Redis at `10.42.1.243`, causing repeated Redis connection timeouts.

Observed Cloud Logging messages on old revisions:

```text
[NolaCache] Redis connection failed, falling back to file cache: Connection timed out
```

Observed affected revisions:

- `sms-api-01001-4dz`
- `sms-api-01002-khp`

The current serving revision `sms-api-01003-q5c` no longer has the Redis env vars or VPC connector.

## Current Admin Endpoint Health

Recent logs for the current serving revision show the admin endpoints are responding successfully:

- `/api/admin_sender_requests.php?action=logs` returns `200`
- `/api/admin_sender_requests.php?action=accounts` returns `200`
- `/api/v2/admin_health` returns `200`
- No `5xx` responses were found in the recent check window
- No `ERROR` logs were found for current revision `sms-api-01003-q5c`

The earlier browser messages on Admin Platform Activity and Logs Explorer match the Redis timeout transition window, not a persistent backend outage on the current revision.

## Code Fix Applied

The admin frontend was polling several endpoints aggressively:

- Logs Explorer live logs: every 5 seconds
- Activity/admin pages: every 15 seconds
- Retry queue: every 10 seconds
- Notifications: every 60 seconds, including hidden tabs

This created unnecessary Cloud Run requests, Firestore reads, and log volume for a 3-5 user system.

Applied frontend changes in the frontend repo:

- Added `useVisibleInterval`, a shared polling helper that skips background refreshes while the browser tab is hidden.
- Changed admin high-traffic polling intervals to 60 seconds.
- Kept manual Refresh buttons for immediate diagnostics.
- Made the dashboard provider-balance poll hidden-tab aware.

Build verification:

```text
npm.cmd run build
```

Result: passed.

## Remaining GCP Action

Redis is now detached but not deleted. Delete it to stop remaining Memorystore billing:

```powershell
gcloud redis instances delete nola-redis `
  --project=nola-sms-pro `
  --region=asia-southeast1
```

Rollback if deletion unexpectedly breaks a hidden dependency:

1. Recreate Basic 1 GiB Redis in `asia-southeast1`.
2. Recreate a VPC connector.
3. Re-add `REDIS_HOST` and `REDIS_PORT` to Cloud Run.
4. Redeploy or update `sms-api`.

Risk is low because live Cloud Run no longer has Redis env vars, the VPC connector is already gone, and current admin endpoints are returning `200`.

## Follow-Up Hardening

- Move plain Cloud Run secrets into Secret Manager.
- Implement or remove the Admin SMS Retry Queue tab because this checkout does not include a backend `action=retry_queue` handler.
- Add Artifact Registry cleanup policy in dry-run mode before enabling deletion.
- Keep Cloud Run min instances at `0` unless cold-start latency becomes unacceptable.
