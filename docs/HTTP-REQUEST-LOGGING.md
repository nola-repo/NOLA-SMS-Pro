# HTTP Request Logging

This repo includes passive Apache request logging for the Cloud Run backend container.

## What It Logs

Incoming HTTP requests are logged to the container terminal/stdout with this prefix:

```text
[NOLA_HTTP]
```

The log line includes safe request metadata only:

- timestamp
- remote IP
- HTTP request line
- response status
- response byte count
- request duration
- referrer
- user agent
- `X-Request-ID`, when provided

It does not log request bodies, cookies, `Authorization`, `X-Webhook-Secret`, POST payloads, or `php://input`.

## Files

- `docker/apache-request-logging.conf`
  - Defines the Apache `LogFormat`.

- `Dockerfile`
  - Copies the Apache logging config into the image.
  - Enables it with `a2enconf nola-request-logging`.
  - Updates the default Apache virtual host to send access logs to stdout using the `nola_request` format.

## Deploy

Because this is a Docker/Apache config change, the backend container must be rebuilt and redeployed before production logs appear.

From the repo root:

```powershell
gcloud builds submit --config=cloudbuild.yaml .
```

The existing `cloudbuild.yaml` builds and deploys:

```text
Cloud Run service: sms-api
Region: asia-southeast1
Image: gcr.io/$PROJECT_ID/sms-api:latest
```

If deploying through GitHub or another CI system, commit and push both files:

```text
Dockerfile
docker/apache-request-logging.conf
```

Note: this repo ignores the `docs/` folder in `.gitignore`, so this documentation file must be force-added if you want it committed:

```powershell
git add -f docs/HTTP-REQUEST-LOGGING.md
```

## Monitor

This installed Google Cloud SDK version supports `logs read`, not `logs tail`.

Use:

```powershell
gcloud run services logs read sms-api --region asia-southeast1 --freshness=10m --limit=50 --log-filter="NOLA_HTTP"
```

On this Windows machine, PowerShell may block `gcloud.ps1` because of execution policy. Use the command shim directly:

```powershell
& "C:\Users\Welcome\AppData\Local\Google\Cloud SDK\google-cloud-sdk\bin\gcloud.cmd" run services logs read sms-api --region asia-southeast1 --freshness=10m --limit=50 --log-filter="NOLA_HTTP"
```

Then call any backend endpoint, for example:

```powershell
curl https://smspro-api.nolacrm.io/api/public/whitelabel
```

Look for log lines beginning with:

```text
[NOLA_HTTP]
```

## Verified Production Result

Deployment was verified on Cloud Run revision:

```text
sms-api-00734-n7k
```

Test endpoint:

```text
https://smspro-api.nolacrm.io/api/public/whitelabel
```

Observed log:

```text
[NOLA_HTTP] ts=2026-06-11T07:17:40+  remote_ip=169.254.169.126 request="GET /api/public/whitelabel HTTP/1.1" status=200 bytes=120
```

## Local Test

If Docker Desktop is running locally:

```powershell
docker build -t nola-sms-pro .
docker run --rm -p 8080:8080 nola-sms-pro
```

In another terminal:

```powershell
curl http://localhost:8080/api/public/whitelabel
```

The container terminal should show a `[NOLA_HTTP]` request log line.
