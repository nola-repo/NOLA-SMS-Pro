# API performance monitoring

The dashboard APIs emit one structured `api_performance` log when a request:

- takes at least 500 ms, or
- finishes with an HTTP 5xx status.

Instrumentation is fail-open and does not log request bodies, response bodies,
authorization headers, tokens, emails, phone numbers, or database values.

## Configuration

No environment variables are required. Optional Cloud Run variables:

- `NOLA_PERF_ENABLED=false` disables instrumentation.
- `NOLA_PERF_SLOW_MS=500` changes the slow-request threshold in milliseconds.

Keep the production threshold at 500 ms initially. Use `0` only during a short
diagnostic window because it logs every instrumented request.

## Instrumented endpoints

- `/api/admin_list_users.php`
- `/api/admin_list_agency_users.php`
- `/api/admin_health.php`
- `/api/admin_sender_requests.php` (including the `accounts` action)
- `/api/agency/get_subaccounts.php`

Responses include `X-Request-ID` and `Server-Timing` headers when headers have
not already been sent. Existing JSON response bodies and status codes are not
changed.

## Read slow-request logs

```powershell
gcloud logging read `
  'resource.type="cloud_run_revision" AND resource.labels.service_name="sms-api" AND jsonPayload.type="api_performance"' `
  --project=nola-sms-pro `
  --freshness=1h `
  --limit=100 `
  --format='table(timestamp,jsonPayload.route,jsonPayload.dimensions.action,jsonPayload.status,jsonPayload.total_ms,jsonPayload.cache_status,jsonPayload.timings_ms.auth,jsonPayload.timings_ms.data_load,jsonPayload.counters.documents_processed)'
```

If the runtime wraps stderr JSON as text, use this fallback filter:

```powershell
gcloud logging read `
  'resource.type="cloud_run_revision" AND resource.labels.service_name="sms-api" AND textPayload:"api_performance"' `
  --project=nola-sms-pro `
  --freshness=1h `
  --limit=100
```

Correlate an API log with the Apache request log using `request_id`. Browser
developer tools also show the same value in the `X-Request-ID` response header.
