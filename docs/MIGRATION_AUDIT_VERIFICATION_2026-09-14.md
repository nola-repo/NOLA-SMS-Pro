# NOLA SMS Pro Migration Audit Verification

Date: 2026-09-14
Scope: Verification of the pasted "GCP to Vercel + Railway" audit against the current repository, current Cloud Run service shape, the August 1-31, 2026 billing screenshot, and current public provider documentation.

## Executive Verdict

The pasted audit is directionally correct on the main risk: do not shut down the GCP/Firebase project as part of a simple Railway/Vercel migration. This application is Firestore-backed, not MySQL-backed. A safe migration can move the PHP API container and static frontends, but Firestore/Firebase must remain unless the team commits to a full database and realtime rewrite.

Recommended path:

1. Keep Firestore/Firebase on GCP.
2. Move only static frontends to Vercel if the frontend repo is ready.
3. Either keep the PHP API on Cloud Run and optimize it, or move the PHP API Docker container to Railway as a hybrid architecture.
4. Remove or replace Memorystore Redis and the VPC connector before doing a bigger platform move, because those are large current cost drivers.

Do not execute any plan that says "migrate MySQL" or "turn off GCP" as a single cutover. That would miss the actual database and realtime layer.

## What I Verified

Repository facts:

- Backend is a PHP 8.2 Apache Docker container, confirmed in `Dockerfile`.
- Cloud Build deploys the backend image to Cloud Run service `sms-api` in `asia-southeast1`, confirmed in `cloudbuild.yaml`.
- Firestore is the database client used by the backend, confirmed in `api/webhook/firestore_client.php`.
- Firebase custom token signing exists for realtime frontend access, confirmed in `api/auth/firebase_token.php`.
- Redis is used as a cache with in-memory/file fallback, confirmed in `api/cache_helper.php`.
- This checked-in backend workspace does not contain the active frontend monorepo described by the pasted audit. It contains frontend reference docs under `docs/frontend-reference/`; the actual frontend may be a separate repo.

Live GCP facts verified from `gcloud run services describe sms-api` on 2026-09-14:

- Cloud Run service name: `sms-api`.
- Region: `asia-southeast1`.
- Runtime image: `gcr.io/nola-sms-pro/sms-api:latest`.
- Container memory: `2Gi`.
- Container CPU: `1000m`.
- Container concurrency: `30`.
- Minimum instances: `1`.
- Maximum instances in the service template: `20`.
- A Serverless VPC Access connector is attached.
- VPC egress is set to `all-traffic`.
- Redis host is configured by private IP, so the VPC connector is currently tied to Memorystore access.

Billing screenshot facts:

- Billing period shown is August 1-31, 2026.
- Total shown is `$151.69`.
- Top cost lines: Cloud Run `$53.30`, Cloud Memorystore for Redis `$49.92`, Compute Engine `$16.82`, Tax `$16.24`, App Engine `$5.42`, Artifact Registry `$5.01`, Networking `$4.14`.

## Corrections To The Pasted Audit

The pasted audit is right that MySQL is not the database. The code uses Firestore heavily for tokens, users, integrations, messages, billing wallets, logs, retry queues, locks, notifications, tickets, and admin state.

The pasted audit is right that Firestore/Firebase cannot be shut down unless the system is rewritten. The PHP backend reads/writes Firestore directly, and `firebase_token.php` signs Firebase custom tokens for realtime frontend access.

The pasted audit is likely wrong or outdated when it says Railway has no built-in cron scheduler. Railway now supports cron jobs as scheduled services, but they must run to completion and exit; minimum frequency is every 5 minutes and schedules are evaluated in UTC.

The pasted audit overstates "zero code changes" for Railway. The Dockerfile currently hardcodes Apache to port `8080`. Railway injects a `PORT` variable and expects the app to listen on that port, or the Railway service must explicitly set `PORT=8080`. This is a small deployment fix, but it is not zero-risk.

The pasted audit says there is a frontend monorepo under `frontend/`. I did not find that in this checked-in backend workspace. Treat frontend migration as a separate-repo task unless the frontend source is restored here.

The pasted audit says Compute Engine may be a MySQL cost. The current evidence points more strongly to the Serverless VPC Access connector, because Cloud Run is attached to a VPC connector and Google bills connector instances through Compute Engine-style resources. Verify in Billing by filtering labels for `serverless-vpc-access`.

The environment variable list in the pasted audit is incomplete for this Docker/Apache setup. `docker-entrypoint.sh` has an explicit `PassEnv` list. Before Railway or Cloud Run redeploys, it should include every runtime secret used by raw PHP, including agency GHL vars, UniSMS vars, Semaphore global key, cron secret, Firebase service-account vars, Redis password if needed, and CORS vars. Values must stay in the platform secret store, not in source.

The live Cloud Run service currently presents several runtime secrets as direct environment variable values in the service configuration output. Do not copy those values into tickets or reports. As part of either optimization or migration, move sensitive values into managed secrets where possible, turn off debug token flags, and rotate any credential that may have been exposed during troubleshooting.

## Current Architecture Map

Keep on GCP/Firebase:

- Firestore database.
- Firebase Authentication/custom-token target project.
- Firestore indexes and rules.
- Service account used by external runtime to access Firestore.
- Optionally Cloud Scheduler if the backend remains on Cloud Run or if you prefer simple HTTPS cron.

Can move:

- PHP backend Docker container from Cloud Run to Railway.
- Static frontend apps to Vercel.
- Redis cache from Memorystore to Railway Redis, Upstash Redis, or no external Redis.
- CI/CD from Cloud Build to Railway/Vercel/GitHub Actions.

Should remove if confirmed unused:

- Orphan Firebase/App Engine keep-warm job.
- Old App Engine service if it is only serving the dead keep-warm path.
- Old container images in Artifact Registry.

## Migration Options

### Option 1: Optimize GCP First

This is the lowest-risk immediate move. Keep Cloud Run, Firestore, and Cloud Scheduler. Remove or replace Memorystore and its VPC connector, then tune Cloud Run.

Expected savings from August bill: approximately `$55` to `$75` before tax if Redis, connector compute, and connector networking are removed.

Use this if the team needs fast savings and minimal migration risk.

### Option 2: Hybrid Vercel + Railway + Firebase

Move static frontends to Vercel and PHP API to Railway. Keep Firestore/Firebase on GCP.

Required changes:

- Make Apache/Railway port handling explicit: either set Railway `PORT=8080` or update the container to listen on `$PORT`.
- Provide Firestore credentials outside GCP using service account JSON or `GOOGLE_APPLICATION_CREDENTIALS`.
- Set all raw PHP environment variables in Railway.
- Add Vercel frontend domains to backend CORS.
- Update GHL OAuth redirects and webhook URLs only if the backend public domain changes.
- Update Semaphore/UniSMS inbound webhook URLs only if the backend public domain changes.
- Recreate cron jobs using Railway cron services or keep Cloud Scheduler calling the Railway URL.

Use this if the team wants to reduce GCP compute dependency but is willing to test a new hosting platform carefully.

### Option 3: Full GCP Exit

Not recommended now. This means replacing Firestore and Firebase realtime with another database and realtime layer, then rewriting backend queries and frontend listeners.

Likely work:

- Export all Firestore collections.
- Design a relational or document schema.
- Rewrite every Firestore query/transaction.
- Replace Firebase realtime subscriptions.
- Replace Firebase custom token signing.
- Rebuild indexes, security model, data access rules, and backfills.

This is a product rewrite, not a hosting migration.

## Recommended Migration Checklist

Phase 0: Backup and freeze risky changes.

- Export Firestore.
- Export Cloud Run service configuration with secrets redacted.
- Export Cloud Scheduler jobs.
- Export current GHL marketplace settings and webhook URLs.
- Confirm frontend repo/source of truth.

Phase 1: Cost cleanup on current GCP.

- Disable or remove Redis from Cloud Run and test fallback.
- Remove VPC connector after Redis is no longer private-network dependent.
- Delete orphan keep-warm scheduler/App Engine resources if verified unused.
- Add Artifact Registry cleanup/lifecycle policy.
- Consider Cloud Run min instances `0` after webhook cold-start testing.

Phase 2: Parallel Railway backend.

- Deploy the same Docker image/repo to Railway.
- Set `PORT=8080` or patch Docker startup to use `$PORT`.
- Set service-account credentials for Firestore access.
- Set all runtime env vars in Railway with no secret values in repo.
- Test health, login, Firestore reads/writes, SMS sends, GHL provider webhook, and retry/status cron endpoints.

Phase 3: Frontend/Vercel.

- Deploy static frontend apps to Vercel.
- Point API env vars to the selected backend domain.
- Keep Firebase frontend config pointed at `nola-sms-pro`.
- Add Vercel domains to backend CORS.

Phase 4: Cutover.

- Prefer keeping `smspro-api.nolacrm.io` as the stable backend domain. Change DNS behind it instead of changing every integration URL.
- If the backend domain changes, update GHL OAuth redirects, GHL marketplace webhooks, conversation provider URLs, Semaphore inbound webhooks, UniSMS inbound webhooks, and cron URLs.
- Run a live smoke test for install, iframe autologin, outbound SMS, inbound SMS, 2-way chat, billing deduction/refund, retry queue, and status sync.

## Final Confirmation

Your boss's target shape can be valid only as a hybrid migration: Vercel/Railway for app hosting, Firebase/Firestore still on GCP. It is not valid as a full GCP shutdown.

The cheaper and safer first move is not a full platform migration. It is to remove the expensive Redis plus VPC connector path, clean unused App Engine/Artifact resources, and tune Cloud Run.

## Sources

- Cloud Run pricing and billing behavior: https://cloud.google.com/run/pricing
- Cloud Run minimum instances billing: https://docs.cloud.google.com/run/docs/configuring/min-instances
- Cloud Run Direct VPC egress: https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc
- Serverless VPC Access connector behavior: https://docs.cloud.google.com/vpc/docs/serverless-vpc-access
- VPC pricing for Serverless VPC Access: https://cloud.google.com/vpc/pricing
- Memorystore for Redis pricing: https://cloud.google.com/memorystore/docs/redis/pricing
- Firebase pricing/free quotas: https://firebase.google.com/pricing
- Railway pricing: https://docs.railway.com/pricing/plans
- Railway cron jobs: https://docs.railway.com/cron-jobs
- Railway port/healthcheck behavior: https://docs.railway.com/deployments/healthchecks
- Vercel pricing: https://vercel.com/pricing
