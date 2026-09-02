# Backend Handoff: Cloud Run SMS Status Sync

**Project:** NOLA SMS Pro  
**Issue:** SMS messages stuck in "Pending" status indefinitely  
**Priority:** High — affects all users  
**Date:** 2026-03-25  

---

## Context

When an SMS is sent via Semaphore, it is saved to Firestore with status `Queued` or `Pending`. The only way status moves to `Sent` or `Delivered` is if the backend **polls Semaphore** for updates.

The backend script that does this is:
- **`api/webhook/retrieve_status.php`** — the HTTP endpoint
- **`api/services/StatusSync.php`** — the service class that does the actual sync

This script must be triggered automatically every 5 minutes from outside the app using **Google Cloud Scheduler**.

---

## What Was Already Done (by Antigravity)

| File | What Changed |
|---|---|
| `api/webhook/retrieve_status.php` | Removed `mkdir()`/`file_put_contents()`. Now uses `error_log()`. Wraps execution in try/catch. |
| `api/services/StatusSync.php` | **NEW FILE** — Stateless class that polls Semaphore and updates Firestore. |

> [!IMPORTANT]
> Cloud Run has a **read-only filesystem**. Any code that writes to local paths like `/var/www/html/logs/` will crash. Use `error_log()` only — logs will appear in Google Cloud Logging.

---

## Step-by-Step: What the Backend Team Must Do

### Step 1 — Deploy the Code to Cloud Run

Ensure the latest code (including `api/services/StatusSync.php` and the updated `api/webhook/retrieve_status.php`) is deployed to your Cloud Run service.

```bash
# Example: build and push image, then deploy
gcloud run deploy nola-sms-pro-backend \
  --image gcr.io/nola-sms-pro/backend:latest \
  --platform managed \
  --region asia-southeast1
```

---

### Step 2 — Get the Cloud Run Service URL

After deploying, note the Cloud Run service URL. It will look like:
```
https://nola-sms-pro-backend-xxxx-uc.a.run.app
```

The cron endpoint is:
```
https://[YOUR-CLOUD-RUN-URL]/api/webhook/retrieve_status.php
```

> [!TIP]
> Test it manually first by opening this URL in a browser or running:
> ```bash
> curl https://[YOUR-CLOUD-RUN-URL]/api/webhook/retrieve_status.php
> ```
> You should see: `Status update complete. Updated X messages.`

---

### Step 3 — Create the Cloud Scheduler Job

1. Open [Google Cloud Scheduler](https://console.cloud.google.com/cloudscheduler)
2. Click **"Create Job"**
3. Fill in the fields:

| Field | Value |
|---|---|
| **Name** | `retrieve-sms-status` |
| **Region** | Same region as your Cloud Run service |
| **Frequency (Cron)** | `*/5 * * * *` |
| **Timezone** | Asia/Manila (or your preferred zone) |
| **Target type** | HTTP |
| **URL** | `https://[YOUR-CLOUD-RUN-URL]/api/webhook/retrieve_status.php` |
| **HTTP Method** | GET |
| **Auth header** | None (if Cloud Run is public) |

4. Click **"Create"**

> [!NOTE]
> If your Cloud Run service requires authentication, set "Auth header" to **Add OIDC token** and choose a service account with the `roles/run.invoker` role.

---

### Step 4 — Verify it Works

#### 4a. Manually Trigger the Job
In Google Cloud Scheduler, click **"Force Run"** on the job to trigger it immediately without waiting 5 minutes.

#### 4b. Check Cloud Logging
Go to [Google Cloud Logging](https://console.cloud.google.com/logs) and filter by:
```
resource.type="cloud_run_revision"
```
You should see `[StatusSync]` log lines like:
```
[StatusSync] Successfully updated message ID 12345678 to status: Sent
[StatusSync] Cron finished: Status update complete. Updated 3 messages.
```

#### 4c. Verify in Firestore
Open [Firestore Console](https://console.cloud.google.com/firestore) and check:
- `sms_logs` collection — `status` field should be `Sent` or `Delivered`
- `messages` collection — same `status` field should also be updated

---

## Architecture Overview

```
Cloud Scheduler (every 5 min)
        │
        ▼  HTTP GET
retrieve_status.php
        │
        ▼
StatusSync::runSync($db, $apiKey)
        │
        ├─► Firestore: query sms_logs WHERE status IN ['Queued', 'Pending']
        │
        ├─► Semaphore API: GET /api/v4/messages/{message_id}?apikey=...
        │
        └─► Firestore: update status in both `sms_logs` and `messages`
```

---

## Key Files Reference

| File | Purpose |
|---|---|
| `api/webhook/retrieve_status.php` | HTTP entry point for Cloud Scheduler |
| `api/services/StatusSync.php` | Stateless sync service class |
| `api/webhook/config.php` | Contains `SEMAPHORE_API_KEY` |
| `api/webhook/firestore_client.php` | Returns a shared `FirestoreClient` instance |

---

## Semaphore API Used

```
GET https://api.semaphore.co/api/v4/messages/{message_id}?apikey={key}
```

**Response:**
```json
[
  {
    "message_id": 12345678,
    "status": "Sent",
    ...
  }
]
```

---

## Common Errors & Fixes

| Error | Likely Cause | Fix |
|---|---|---|
| `mkdir(): Permission denied` | Old code writing to local disk | Deploy updated `retrieve_status.php` (logging removed) |
| `HTTP 401 from Semaphore` | Wrong API key | Check `SEMAPHORE_API_KEY` in `config.php` or env vars |
| Logs show 0 messages updated | No Pending records in Firestore | Send a test SMS and trigger the job manually |
| Cloud Scheduler job fails with 500 | PHP fatal error | Check Cloud Logging for the exact exception |

---

## Environment Variables

Verify these are set in Cloud Run:

| Variable | Description |
|---|---|
| `SEMAPHORE_API_KEY` | Semaphore master API key (fallback if no custom key) |
| `GOOGLE_CLOUD_PROJECT` | Firebase/Firestore project ID (`nola-sms-pro`) |

> [!CAUTION]
> Never hardcode API keys in source files. Use Cloud Run environment variables or Secret Manager for production.
