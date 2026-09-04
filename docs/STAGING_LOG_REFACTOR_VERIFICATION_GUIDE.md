# Staging Verification Guide: Log Error Refactors

Date: September 4, 2026

Use this after pushing the refactor branch to staging and deploying `sms-api-staging`.

## 1. Deployment Sanity

- Confirm Cloud Run has a new `sms-api-staging` revision from the staging branch.
- Open the staging admin app and confirm login still works.
- Watch Cloud Run logs for immediate PHP fatal errors:
  - `gcloud logging read 'resource.type="cloud_run_revision" AND resource.labels.service_name="sms-api-staging" AND severity>=ERROR' --project=nola-sms-pro --limit=50 --freshness=30m`

## 2. Redis / Cache Fast-Fail

Call staging admin health:

```bash
curl -i "https://sms-api-staging-<hash>.a.run.app/api/v2/admin_health" \
  -H "Authorization: Bearer <ADMIN_JWT>"
```

Expected:

- HTTP 200.
- `data.cache.redis_host` is `not_set` if staging has no Redis.
- `data.cache.redis_disabled` is `true`.
- `data.cache.driver` is `memory_fallback` when running in Cloud Run without Redis.
- No repeated 1-second Redis connection timeout messages in logs.
- Admin health should not spend several seconds in provider balance aggregation.

## 3. Provider Balance Dashboard

Call:

```bash
curl -i "https://sms-api-staging-<hash>.a.run.app/api/admin/provider-balances?bypass_cache=1" \
  -H "Authorization: Bearer <SUPER_ADMIN_JWT>"
```

Expected:

- HTTP 200.
- Response still includes `summary.semaphore`, `summary.unisms`, `providers`, `active_provider`, `is_stale`, and `data_quality`.
- Request-time response should only check system provider keys, not scan hundreds of `integrations`.
- `summary.*.total_accounts` should normally be `1` for configured system keys unless `admin_config/provider_balance_summary` exists and reports a background aggregate.
- Cloud Run logs should not show Semaphore 429 bursts from loading the admin dashboard.

## 4. Admin Logs Payload

Call:

```bash
curl -s "https://sms-api-staging-<hash>.a.run.app/api/admin_sender_requests.php?action=logs&limit=50" \
  -H "Authorization: Bearer <ADMIN_JWT>" \
  -o /tmp/admin-logs.json
```

Expected:

- Response has `status: success`, `data`, `summary`, `total_messages`, and `pagination`.
- `data` length is at most 50.
- Rows are compact and do not include raw nested `provider_response`.
- Activity and Logs Explorer screens still render searchable rows.
- Approximate payload target: under 100 KB for `limit=50`.

Optional cursor check:

```bash
NEXT_CURSOR="<pagination.next_cursor from prior response>"
curl -s "https://sms-api-staging-<hash>.a.run.app/api/admin_sender_requests.php?action=logs&limit=50&start_after=${NEXT_CURSOR}" \
  -H "Authorization: Bearer <ADMIN_JWT>"
```

Expected: HTTP 200 and no duplicate newest rows from the first page.

## 5. Sender Requests Payload

Call:

```bash
curl -s "https://sms-api-staging-<hash>.a.run.app/api/admin_sender_requests.php?limit=100" \
  -H "Authorization: Bearer <ADMIN_JWT>"
```

Expected:

- `data` length is at most 100.
- Existing Sender ID Requests admin page can still approve, reject, revoke, and delete.
- Request cards still show `reference_id` / `request_reference_id`, provider badge, location, status, and dates.

## 6. UniSMS Link Policy Pre-Validation

Prepare a staging integration document for a UniSMS test subaccount:

- `provider_preference`: `unisms` or `unisms_custom`
- `approved_sender_id` / `unisms_sender_id`: the tested sender
- Add either `links_prohibited: true` or `allow_links: false`

Send a test SMS body containing a URL, such as `Please visit https://example.com`.

Expected:

- HTTP 422.
- Response contains `error: unisms_links_prohibited`.
- No UniSMS provider request is made.
- No paid credit deduction or trial usage increment is created for this blocked send.
- A blocked message event is recorded with provider `unisms` and reason `unisms_links_prohibited`.

Then set `allow_links: true` or remove the policy fields and repeat with a low-risk test recipient.

Expected:

- Request proceeds to the normal UniSMS send path.
- Billing behavior matches the current trial/paid rules.

## 7. Regression Smoke

- Admin dashboard loads without long spinners.
- Provider balance card still shows Semaphore and UniSMS.
- Logs Explorer filters still work for SMS, credits, sender requests, provider errors, and validation errors.
- A Semaphore send without URLs still succeeds.
- A UniSMS send without URLs still succeeds.
- A system notification still bypasses billing.

## 8. Success Criteria

- Staging admin health returns in roughly sub-second to low-single-digit latency.
- `GET action=logs&limit=50` stays small and bounded.
- `GET sender_requests` is capped at 100 rows.
- No Redis timeout tax appears when staging has no Redis.
- No dashboard-triggered 429 burst appears in Semaphore logs.
- UniSMS link policy failures happen as local 422 validation before provider dispatch and before billing.
