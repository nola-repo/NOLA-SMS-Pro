# NOLA SMS Pro Cost Reduction And Root Cause Audit

Date: 2026-09-14
Billing evidence: screenshot for August 1-31, 2026, total `$151.69`.

## Executive Summary

The August bill increased because the system is no longer just paying for the Cloud Run API. It is also paying for a managed Redis instance, the private-network plumbing required to reach that Redis instance, App Engine/legacy resources, retained container images, networking, and tax.

The fastest meaningful savings are:

1. Remove or replace Cloud Memorystore Redis.
2. Remove the Serverless VPC Access connector after Redis is gone.
3. Delete orphan App Engine/keep-warm resources after verifying they are unused.
4. Add Artifact Registry cleanup.
5. Tune Cloud Run min instances and memory after smoke tests.

Estimated realistic monthly target without a full migration: around `$60` to `$85`, depending on whether production keeps one warm Cloud Run instance. Estimated target with a careful Railway/Vercel/Firebase hybrid: around `$30` to `$60` plus Firebase/Firestore usage, depending on Railway service sizing and whether Redis is external.

## August 2026 Cost Breakdown

| Service | Cost | Share of total | Assessment |
|---|---:|---:|---|
| Cloud Run | `$53.30` | 35.1% | Expected primary API cost, but min instance and 2Gi memory create baseline spend. |
| Cloud Memorystore for Redis | `$49.92` | 32.9% | Biggest removable service. Current code treats Redis as cache, not database. |
| Compute Engine | `$16.82` | 11.1% | Likely Serverless VPC Access connector backing VMs, not app-owned MySQL. Verify billing labels. |
| Tax | `$16.24` | 10.7% | Follows taxable subtotal; reduce by reducing services. |
| App Engine | `$5.42` | 3.6% | Likely legacy/orphan workload. Needs console verification before deletion. |
| Artifact Registry | `$5.01` | 3.3% | Usually old container images/storage. Add cleanup policy. |
| Networking | `$4.14` | 2.7% | Likely VPC connector/all-traffic egress and normal outbound traffic. |
| Cloud Storage | `$0.55` | 0.4% | Low priority. |
| Cloud Scheduler | `$0.26` | 0.2% | Low cost, keep useful jobs. |
| Secret Manager | `$0.03` | 0.0% | Keep. Not worth replacing. |

Pretax subtotal is `$135.45`. Cloud Run + Redis + Compute Engine + App Engine + Artifact Registry + Networking account for `$134.61`, almost the entire pretax bill.

## Why It Used To Be Around `$50`

The past `$50` pattern probably represented Cloud Run as the only meaningful always-on cost. The current August bill adds several new or newly-visible baseline services:

- Redis alone adds `$49.92`, nearly the exact size of the old monthly bill.
- Serverless VPC Access likely adds `$16.82` in Compute Engine-style charges because connectors run at least two connector instances and cannot scale down like Cloud Run.
- VPC `all-traffic` egress can add Networking charges, especially because the API calls external services like GHL, Semaphore, and UniSMS.
- Artifact Registry grows when old images are retained after frequent deployments.
- App Engine appears to still have something billable, likely a legacy keep-warm or default service.

In plain terms: the system appears to have moved from "one serverless API baseline" to "API + managed Redis + private network connector + leftover legacy resources."

## Cost Driver 1: Memorystore Redis

Current cost: `$49.92`.

Code impact:

- `api/cache_helper.php` uses Redis only when `REDIS_HOST` is configured and reachable.
- If Redis is absent, the helper falls back to memory/file cache.
- Important persistent data is already in Firestore, not Redis.
- `SemaphoreBalanceFetcher.php` uses Redis as tier-2 cache but also has Firestore last-known-good fallback.

Recommendation:

- First test production with `REDIS_HOST` removed in staging or a temporary revision.
- If behavior and latency are acceptable, delete Memorystore.
- If shared cache is still needed, replace Memorystore with a cheaper option.

Alternatives:

| Option | Expected cost | Tradeoff |
|---|---:|---|
| No external Redis | `$0` | More Firestore/provider reads, but simplest and likely acceptable at current scale. |
| Upstash Redis pay-as-you-go | Often low for small cache loads | Requires Redis TLS/connection config check; pay per command. |
| Upstash fixed 250MB | `$10/month` | Predictable cost, still cheaper than `$49.92`. |
| Railway Redis | Usage-based as part of Railway project | Best if PHP API also moves to Railway. |
| Keep Memorystore | `$49.92+` | Only justified if low-latency private Redis is truly needed. |

## Cost Driver 2: VPC Connector / Compute Engine / Networking

Current related costs: Compute Engine `$16.82`, Networking `$4.14`.

Live Cloud Run is attached to a VPC connector with `all-traffic` egress. That is expensive for this workload because most backend calls are outbound HTTPS to public APIs and Google services. The VPC path appears to exist mainly so Cloud Run can reach private-IP Memorystore.

Recommendation:

1. Remove or replace Memorystore first.
2. Change Cloud Run egress away from `all-traffic`.
3. Remove the Serverless VPC Access connector from Cloud Run if no private VPC resource remains.
4. If a private VPC resource is still needed, consider Cloud Run Direct VPC egress or private-ranges-only routing.

Expected savings: roughly `$15` to `$25/month`, plus reduced networking/tax.

## Cost Driver 3: Cloud Run Baseline

Current cost: `$53.30`.

Live config:

- Minimum instances: `1`.
- Memory: `2Gi`.
- CPU: `1 vCPU`.
- Request-based CPU throttling is enabled.

Why this costs money:

- Cloud Run is pay-per-use, but minimum instances keep an idle instance warm and billed at idle rates.
- More memory increases idle and active instance cost.
- Cron jobs and repeated health/status checks also keep the service active.

Options:

| Change | Savings | Risk |
|---|---:|---|
| Set min instances from `1` to `0` | Medium/high | Cold starts may affect GHL webhooks and iframe login. Must smoke test. |
| Reduce memory from `2Gi` to `1Gi` | Medium | Possible Composer/Laravel/PHP memory headroom issue under bulk sends. Staging already uses `1Gi`, so testable. |
| Keep min `1`, reduce memory | Medium | Safer than min `0`. |
| Buy committed use discount | Low/medium | Useful only if Cloud Run remains always-on. |

Recommended first test:

- Deploy a staging or canary revision with `1Gi` memory.
- Run install, auth, message history, SMS send, GHL provider, retry queue, and status sync.
- Then test min instances `0` separately.

## Cost Driver 4: App Engine And Orphan Keep-Warm

Current cost: `$5.42`.

The repo contains several docs warning about `firebase-schedule-keepWarm-asia-southeast1`, a legacy Cloud Scheduler job calling an old Firebase Functions URL. The screenshot also shows App Engine cost.

Recommendation:

- In GCP console, verify whether App Engine has a live default service receiving traffic.
- If it is only the old keep-warm/decommissioned function path, delete or disable it.
- Delete the orphan scheduler job after verification.

Expected savings: about `$5/month` plus less log noise. Not huge, but clean.

## Cost Driver 5: Artifact Registry

Current cost: `$5.01`.

This is usually retained container images. The service has had many revisions; frequent image pushes without cleanup can accumulate storage.

Recommendation:

- Keep the latest N production images and latest N staging images.
- Delete older untagged images.
- Add Artifact Registry cleanup policy.

Expected savings: up to `$5/month` now, and prevents growth.

## What Not To Remove

Do not remove Firestore/Firebase. It is the database and realtime foundation.

Do not remove Cloud Scheduler blindly. It is cheap and runs important jobs. Keep or migrate these jobs:

- SMS status sync.
- SMS retry queue.
- Monthly credit reset.
- Auto-recharge if active.
- Connectivity health check, if still useful.

Do not remove Secret Manager just to save `$0.03`. It is doing the right job.

Do not remove Cloud Storage without checking what Firebase/Firestore exports, docs assets, or app assets use it for.

## Immediate 7-Day Plan

Day 1:

- Export Firestore backup.
- Export current billing CSV.
- Verify Redis memory usage and command count.
- Verify Compute Engine line by label for `serverless-vpc-access`.
- Verify App Engine services and versions.

Day 2:

- Deploy staging with Redis disabled.
- Confirm admin dashboard, provider balances, conversations, SMS send, status sync, retry queue, auth, and billing still work.

Day 3:

- If staging passes, remove Redis from production Cloud Run env.
- Watch logs and latency for 24 hours.

Day 4:

- Delete Memorystore.
- Remove VPC connector / all-traffic egress from Cloud Run.
- Watch Compute Engine and Networking lines drop in daily billing.

Day 5:

- Add Artifact Registry cleanup.
- Delete orphan keep-warm scheduler and App Engine resources if verified unused.

Day 6:

- Test Cloud Run `1Gi` memory.
- If stable, deploy `1Gi` to production.

Day 7:

- Test min instances `0` during low-traffic hours.
- Decide whether cold starts are acceptable. If not, keep min `1`.

## Expected Savings Scenarios

Conservative cleanup:

- Remove Redis: save about `$49.92`.
- Remove VPC connector/related compute/networking: save about `$15` to `$25`.
- Clean App Engine and Artifact Registry: save about `$5` to `$10`.
- New expected total before tax impact: roughly `$65` to `$85`.

Aggressive GCP optimization:

- Above cleanup plus Cloud Run memory reduction and min instances `0`.
- New expected total: roughly `$35` to `$65`, depending on traffic and cold-start tolerance.

Hybrid Railway/Vercel/Firebase:

- Vercel frontend: likely `$0` to `$20+`, depending on team plan and usage.
- Railway backend: `$5` to `$20+` minimum/usage depending on plan and resources.
- Redis: `$0`, low pay-as-you-go, or `$10+`.
- Firebase/Firestore remains.
- New expected total: roughly `$30` to `$60+`, but only after careful migration testing.

## Why A Full Migration Is Not The First Cost Fix

The August bill shows a cost architecture problem, not proof that GCP itself is too expensive. Redis plus VPC connector can be removed while staying on Cloud Run. That likely gets most of the savings with much less risk than changing the hosting platform and all integration URLs.

The best sequence is:

1. Cut waste on GCP.
2. Stabilize cost back near the old `$50` baseline.
3. Run Railway/Vercel as a parallel proof-of-concept.
4. Move only if the operational savings are still worth the migration risk.

## Sources

- Cloud Run pricing and billing behavior: https://cloud.google.com/run/pricing
- Cloud Run minimum instances billing: https://docs.cloud.google.com/run/docs/configuring/min-instances
- Cloud Run Direct VPC egress: https://docs.cloud.google.com/run/docs/configuring/vpc-direct-vpc
- Serverless VPC Access connector behavior: https://docs.cloud.google.com/vpc/docs/serverless-vpc-access
- VPC pricing for Serverless VPC Access: https://cloud.google.com/vpc/pricing
- Memorystore for Redis pricing: https://cloud.google.com/memorystore/docs/redis/pricing
- Cloud Scheduler pricing: https://cloud.google.com/scheduler/pricing
- Artifact Registry pricing: https://cloud.google.com/artifact-registry/pricing
- Firebase pricing/free quotas: https://firebase.google.com/pricing
- Railway pricing: https://docs.railway.com/pricing/plans
- Railway cron jobs: https://docs.railway.com/cron-jobs
- Railway port/healthcheck behavior: https://docs.railway.com/deployments/healthchecks
- Upstash Redis pricing: https://upstash.com/pricing/redis
- Vercel pricing: https://vercel.com/pricing
