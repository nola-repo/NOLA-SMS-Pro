# NOLA SMS Pro GCP Cost Cleanup Implementation Plan

Date: 2026-09-15
Scope: `nola-sms-pro` only. Excludes `nola-paymongo`.

## Goal

Cut unnecessary GCP baseline services while keeping NOLA SMS Pro on GCP:

- Remove Redis/Memorystore dependency.
- Remove Serverless VPC Access dependency.
- Reduce Cloud Run baseline spend.
- Stop Artifact Registry storage growth.
- Delete verified orphan keep-warm/App Engine resources.
- Keep Firestore, Firebase, Secret Manager, and useful Cloud Scheduler jobs.

## Current September Evidence

From the September 1-15 billing CSV, excluding Cloud SQL because it belongs to `nola-paymongo`:

| Driver | Sep 1-15 cost signal | Action |
|---|---:|---|
| Cloud Memorystore for Redis | `$21.16` | Remove from Cloud Run, then delete Memorystore. |
| Cloud Run min instance CPU/memory | `$20.47` subtotal before active request usage | Set min instances to `0`, memory to `1Gi`. |
| Compute Engine VPC connector backing resources | `$6.73` plus disk | Clear VPC connector after Redis removal. |
| Cloud NAT/networking | `$2.54` | Should drop after VPC/all-traffic cleanup. |
| Artifact Registry | `$2.46` | Add cleanup policy and delete old images. |
| Cloud Scheduler | `$0.03` | Keep useful jobs; delete only orphan keep-warm job. |
| Firestore/App Engine read ops | `$0.11` | Keep. This is normal database usage. |

Projected monthly savings from the first pass: roughly `$70-95/month` before tax effects.

## Code/Config Changes Already Applied

`cloudbuild.yaml` now deploys production with:

- `--min-instances 0`
- `--max-instances 5`
- `--memory 1Gi`
- `--remove-env-vars REDIS_HOST,REDIS_PORT,REDIS_PASSWORD`
- `--clear-vpc-connector`

This prevents future deployments from recreating the expensive Redis/VPC/2Gi/min-instance baseline.

## Live Apply Order

### Phase 1: Update Cloud Run Service

Run:

```bash
gcloud run services update sms-api \
  --project=nola-sms-pro \
  --region=asia-southeast1 \
  --min-instances=0 \
  --max-instances=5 \
  --memory=1Gi \
  --remove-env-vars=REDIS_HOST,REDIS_PORT,REDIS_PASSWORD \
  --clear-vpc-connector
```

Expected effect:

- Cloud Run stops connecting to Memorystore.
- Cloud Run no longer needs the VPC connector.
- Idle Cloud Run minimum-instance spend drops.
- Active request memory cost is lower.

Rollback:

```bash
gcloud run services update sms-api \
  --project=nola-sms-pro \
  --region=asia-southeast1 \
  --min-instances=1 \
  --max-instances=20 \
  --memory=2Gi \
  --update-env-vars=REDIS_HOST=10.42.1.243,REDIS_PORT=6379 \
  --vpc-connector=projects/nola-sms-pro/locations/asia-southeast1/connectors/nola-vpc-connector \
  --vpc-egress=all-traffic
```

### Phase 2: Smoke Test After Cloud Run Update

Check these immediately:

- `/api/admin/admin_health.php`
- Login/auth.
- Admin dashboard.
- Provider balances.
- Account profile.
- Conversations/messages.
- Send one low-risk SMS.
- GHL provider webhook path if testable.
- Cloud Scheduler jobs: status sync and retry queue.

Watch logs for:

- Redis connection errors.
- PHP fatal errors.
- 500 spikes.
- Cloud Run cold start latency.
- Firestore permission errors.

### Phase 3: Delete Memorystore

Only after Phase 1 runs and smoke tests pass.

Find instance:

```bash
gcloud redis instances list \
  --project=nola-sms-pro \
  --region=asia-southeast1
```

Delete:

```bash
gcloud redis instances delete <redis-instance-name> \
  --project=nola-sms-pro \
  --region=asia-southeast1
```

Rollback:

- Recreate a Basic M1 Redis instance in `asia-southeast1`.
- Re-add `REDIS_HOST` and `REDIS_PORT` to Cloud Run.
- Reattach the VPC connector only if Redis is private-IP only.

### Phase 4: Delete Serverless VPC Connector

Only after Cloud Run has no VPC connector attached and Redis is deleted.

Check:

```bash
gcloud compute networks vpc-access connectors list \
  --project=nola-sms-pro \
  --region=asia-southeast1
```

Delete:

```bash
gcloud compute networks vpc-access connectors delete nola-vpc-connector \
  --project=nola-sms-pro \
  --region=asia-southeast1
```

Rollback:

- Recreate the connector only if a private VPC resource is reintroduced.

### Phase 5: Artifact Registry Cleanup

List repositories:

```bash
gcloud artifacts repositories list \
  --project=nola-sms-pro \
  --location=asia-southeast1
```

Set policy:

- Keep latest production images.
- Keep latest staging images.
- Delete old untagged images.

Preferred policy target:

- Keep latest 10 tagged images per package.
- Delete untagged images older than 7 days.

### Phase 6: Delete Orphan Keep-Warm Scheduler

List jobs:

```bash
gcloud scheduler jobs list \
  --project=nola-sms-pro \
  --location=asia-southeast1
```

Delete only if present and pointing to dead Firebase Function:

```bash
gcloud scheduler jobs delete firebase-schedule-keepWarm-asia-southeast1 \
  --project=nola-sms-pro \
  --location=asia-southeast1
```

Keep:

- `retrieve-sms-status`
- `sms-retry-queue-worker`
- monthly credit reset
- auto-recharge if enabled
- connectivity health check if still wanted

## Success Criteria

Within 24-48 hours:

- Memorystore daily cost stops.
- Compute Engine/VPC connector daily cost drops.
- Cloud NAT usage drops.
- Cloud Run minimum instance CPU/memory drops.
- App still handles login, dashboards, SMS sending, webhooks, and scheduled jobs.

Target monthly cost for `nola-sms-pro` after cleanup:

- Conservative: `$55-75/month`
- Aggressive if min instances `0` works well: `$35-60/month`

