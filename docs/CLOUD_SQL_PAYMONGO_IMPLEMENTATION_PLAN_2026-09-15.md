# Cloud SQL PayMongo Cost Investigation And Removal Plan

Date: 2026-09-15

## Executive Verdict

Do not delete this Cloud SQL instance as part of the NOLA SMS Pro Redis/VPC cleanup.

The September billing CSV shows Cloud SQL spend, but live project checks found that the instance is not in the `nola-sms-pro` project. The billed instance is in the separate `nola-paymongo` project:

- Project: `nola-paymongo`
- Instance: `paymongo-db-v1`
- Connection name: `nola-paymongo:asia-southeast1:paymongo-db-v1`
- Engine: MySQL 8.0
- Region/zone: `asia-southeast1` / `asia-southeast1-a`
- Tier: `db-f1-micro`
- State: `RUNNABLE`
- Created: 2026-03-14T09:09:45.901Z
- Database present: `paymongo`
- Related Cloud Run service present: `paymongo-app`

Cloud SQL audit logs show recent `cloudsql.instances.connect` events from the `nola-paymongo` default compute service account. That means the instance is actively being connected to by a service in the PayMongo project. Treat it as potentially required until payment-flow verification proves otherwise.

## Evidence Summary

### Billing Evidence

The September 1-15 billing CSV includes:

- Cloud SQL for MySQL zonal micro instance in Singapore: 326 hours, `$4.79`
- Cloud SQL standard storage in Singapore: 4.53 GiB-month, `$1.08`
- Project-level attribution was not included in the CSV, so service/SKU grouping alone made it look like a NOLA SMS Pro cost.

### NOLA SMS Pro Code And Deployment Evidence

The NOLA SMS Pro repo does not contain active MySQL application code.

Observed SQL-related code is Laravel framework scaffolding:

- `laravel/config/database.php` defines default Laravel `sqlite`, `mysql`, `mariadb`, `pgsql`, and `sqlsrv` connection templates.
- `laravel/.env.example` defaults to `DB_CONNECTION=sqlite`.
- Migrations under `laravel/database/migrations` are default Laravel users/cache/jobs scaffolding.

Production startup does not generate or pass MySQL env vars:

- `docker-entrypoint.sh` writes `SESSION_DRIVER=file`, `CACHE_STORE=file` or `redis`, and `QUEUE_CONNECTION=sync`.
- `docker-entrypoint.sh` does not write `DB_HOST`, `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DATABASE_URL`, or a Cloud SQL connection name.
- Apache `PassEnv` does not pass DB env vars to PHP.

Live `nola-sms-pro` Cloud Run config had no `DB_*`, `DATABASE_URL`, or Cloud SQL attachment. Secret Manager in `nola-sms-pro` listed no DB secrets.

Conclusion for NOLA SMS Pro: no Cloud SQL removal is needed in the SMS API repo or Cloud Run service.

### PayMongo Project Evidence

The accessible Cloud SQL inventory found:

- `nola-paymongo/paymongo-db-v1`
- public IP enabled
- authorized network `119.94.171.156/32`
- automatic backups disabled
- deletion protection disabled
- database `paymongo`
- users `root` and `root@%`
- related Cloud Run service `paymongo-app`

Cloud SQL logs showed recent connect events:

- method: `cloudsql.instances.connect`
- principal: `205396437939-compute@developer.gserviceaccount.com`
- resource: `projects/nola-paymongo/instances/paymongo-db-v1`
- status: OK

This is strong evidence of active Cloud Run-to-Cloud SQL connectivity.

## Open Questions Before Any Removal

1. Is `paymongo-app` still used for live checkout, top-up, subscription, payment webhook, or wallet-crediting flows?
2. Do NOLA SMS Pro frontend checkout links still point to `paymongo-app`, another PayMongo URL, or an external hosted checkout page?
3. Does the `paymongo` database contain recent payment, customer, checkout, webhook, or transaction rows?
4. Are Cloud SQL connections scheduled health checks only, or real payment traffic?
5. Is `nola-paymongo` billing intended to be part of NOLA SMS Pro operating cost, or a separate payment project budget?

## Manual Checks To Resolve Uncertainty

### Billing Attribution

In GCP Billing Reports:

1. Set date range to September 1-15, 2026.
2. Add grouping by `Project`, then `Service`, then `SKU`.
3. Confirm Cloud SQL rows are under `nola-paymongo`, not `nola-sms-pro`.
4. Keep the Cloud SQL spend in the cost report, but label it as PayMongo payment infrastructure.

### Cloud Run Dependency Check

In project `nola-paymongo`, inspect Cloud Run service `paymongo-app`:

- Environment variables: look for `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `DATABASE_URL`, `INSTANCE_CONNECTION_NAME`.
- Service annotations: look for `run.googleapis.com/cloudsql-instances`.
- Traffic: confirm whether it receives user or webhook traffic.
- Logs: filter recent requests by payment endpoints such as checkout, success, cancel, webhook, top-up, subscription, and credit.

### Database Content Check

Using Cloud SQL Studio or a controlled database client:

1. Connect to `nola-paymongo:asia-southeast1:paymongo-db-v1`.
2. Inspect table names only first:

```sql
SHOW TABLES FROM paymongo;
```

3. Check row counts and latest timestamps:

```sql
SELECT table_name, table_rows
FROM information_schema.tables
WHERE table_schema = 'paymongo'
ORDER BY table_name;
```

4. For tables with timestamp columns, check recency:

```sql
SELECT MAX(created_at), MAX(updated_at) FROM paymongo.<table_name>;
```

If recent rows exist, do not remove the database until the payment flow is migrated or confirmed obsolete.

## Safe Implementation Plan

### Phase 0: Do Not Touch NOLA SMS Pro For Cloud SQL

No action in `nola-sms-pro` Cloud Run or repo is required for Cloud SQL specifically.

Do not remove Laravel `config/database.php` scaffolding just to save cost. It is not what is creating the Cloud SQL bill.

### Phase 1: Confirm PayMongo App Ownership And Live Usage

Owner: infrastructure/backend

Actions:

1. Verify whether frontend checkout/top-up links route to `paymongo-app` or another payment surface.
2. Review `paymongo-app` Cloud Run env vars and service annotations.
3. Review Cloud Run request logs for the last 30 days.
4. Inspect the `paymongo` database tables and latest row timestamps.

Exit criteria:

- If recent payment/webhook/crediting traffic exists, keep the instance and optimize separately.
- If no real traffic exists and data is stale, proceed to Phase 2.

### Phase 2: Backup Before Any Trial Disable

Owner: infrastructure

Actions:

1. Enable a final on-demand backup or export.
2. Export SQL to a Cloud Storage bucket.
3. Record instance metadata, authorized networks, users, database names, and service env vars.

Example export command, after choosing a bucket:

```bash
gcloud sql export sql paymongo-db-v1 gs://<backup-bucket>/cloudsql/paymongo-db-v1-2026-09-15.sql \
  --project=nola-paymongo \
  --database=paymongo
```

Rollback input needed:

- SQL export path
- Instance connection name
- Database user names
- Cloud Run service revision before changes

### Phase 3: Trial Disable Without Deletion

Only run this after Phase 1 proves `paymongo-app` is not production-critical.

Preferred trial:

1. Disable or pause `paymongo-app` traffic if no production payment route depends on it.
2. Stop Cloud SQL for 24-72 hours.
3. Watch NOLA SMS Pro top-up, subscription, billing webhook, and crediting flows.

Trial stop command:

```bash
gcloud sql instances patch paymongo-db-v1 \
  --project=nola-paymongo \
  --activation-policy=NEVER
```

Fast rollback:

```bash
gcloud sql instances patch paymongo-db-v1 \
  --project=nola-paymongo \
  --activation-policy=ALWAYS
```

Expected savings from stopping: about `$11-12/month`.

### Phase 4: Delete Only After A Quiet Trial

Delete only if:

- Backups exist.
- `paymongo-app` is confirmed unused or migrated.
- No payment, webhook, checkout, or crediting failures occur during the trial.
- Stakeholders confirm no need for historical SQL access.

Deletion command:

```bash
gcloud sql instances delete paymongo-db-v1 --project=nola-paymongo
```

## Related Cleanup If It Is Obsolete

If Phase 1 proves the PayMongo app is obsolete, remove or archive:

- `nola-paymongo` Cloud Run service `paymongo-app`
- Artifact Registry images for `paymongo-app`
- Cloud SQL instance `paymongo-db-v1`
- Any stale DNS, checkout links, webhooks, or PayMongo dashboard webhook URLs pointing at `paymongo-app`
- Any hardcoded checkout links in NOLA SMS Pro frontend/docs

## Current Recommendation

Classify the Cloud SQL instance as:

`(c) unclear / likely active payment-side dependency`

Reason: NOLA SMS Pro does not use MySQL, but `nola-paymongo` has a live `paymongo-app` service and recent Cloud SQL connection logs from the project compute service account. This is not safe to delete until the PayMongo app and database recency checks are complete.

