# Deploy NOLA SMS API to Google Cloud Run

Step-by-step guide to build and run the SMS API (PHP + Firestore) on Cloud Run.

---

## Prerequisites

- Google Cloud project: **nola-sms-pro** (Project ID: `nola-sms-pro`)
- [Google Cloud SDK (gcloud)](https://cloud.google.com/sdk/docs/install) installed and logged in
- Billing enabled on the project (required for Cloud Run)

---

## Step 1: Log in and set project

Open a terminal (PowerShell or Command Prompt) and run:

```bash
gcloud auth login
gcloud config set project nola-sms-pro
```

---

## Step 2: Enable required APIs

```bash
gcloud services enable cloudbuild.googleapis.com
gcloud services enable run.googleapis.com
gcloud services enable containerregistry.googleapis.com
```

(Firestore is already enabled if you created the database in Firebase.)

---

## Step 3: Give Cloud Run access to Firestore

Cloud Run uses a **service account** to call Firestore. Use the default compute account or a custom one.

**Option A – Use default Cloud Run service account**

1. In [GCP Console](https://console.cloud.google.com) → **IAM & Admin** → **IAM**.
2. Find the principal: **`nola-sms-pro@appspot.gserviceaccount.com`** or **`PROJECT_NUMBER-compute@developer.gserviceaccount.com`** (replace PROJECT_NUMBER with your project number, e.g. `116662437564`).
3. If it’s not there, go to **Cloud Run** → your service → **Security** tab and note the service account used (e.g. **Default compute service account**).
4. Click the **pencil (Edit)** for that principal.
5. Add role: **Cloud Datastore User** (or **Firestore User** if listed). Save.

**Option B – Use a custom service account (recommended)**

1. **IAM & Admin** → **Service Accounts** → **Create Service Account**.
2. Name: e.g. `sms-api-run`.
3. Grant role: **Cloud Datastore User** (or **Firestore** roles as needed). Create key only if you need a JSON key for local use; for Cloud Run you don’t.
4. When you deploy (Step 6), pass:  
   `--service-account=sms-api-run@nola-sms-pro.iam.gserviceaccount.com`

---

## Step 4: Build the container image

From your **project root** (the folder that contains `Dockerfile`, `composer.json`, and `api/`):

```bash
cd C:\Users\niceo\public_html
```

**Option A – Build with Cloud Build (recommended)**

```bash
gcloud builds submit --config=cloudbuild.yaml .
```

This builds the image and pushes it to `gcr.io/nola-sms-pro/sms-api:latest`.

**Option B – Build locally with Docker**

If you have Docker Desktop installed:

```bash
docker build -t gcr.io/nola-sms-pro/sms-api:latest .
docker push gcr.io/nola-sms-pro/sms-api:latest
```

(Configure Docker for GCR first: `gcloud auth configure-docker`.)

---

## Step 5: Deploy to Cloud Run

```bash
gcloud run deploy sms-api ^
  --image gcr.io/nola-sms-pro/sms-api:latest ^
  --platform managed ^
  --region asia-southeast1 ^
  --allow-unauthenticated ^
  --port 8080
```

- Pick the **region** you use for Firestore (e.g. `asia-southeast1`).
- After deploy, Cloud Run prints the **service URL**, e.g.  
  `https://sms-api-xxxxx-asia-southeast1.run.app`

On **Linux/macOS** use backslash instead of `^`:

```bash
gcloud run deploy sms-api \
  --image gcr.io/nola-sms-pro/sms-api:latest \
  --platform managed \
  --region asia-southeast1 \
  --allow-unauthenticated \
  --port 8080
```

---

## Step 6: Set environment variables (secrets)

Your PHP code reads from `config.php`. For production you can move secrets to Cloud Run env vars and read them with `getenv()` in PHP. For a first deploy you can keep using `config.php` inside the image.

**Optional – pass env vars at deploy time:**

```bash
gcloud run services update sms-api ^
  --region asia-southeast1 ^
  --set-env-vars "SEMAPHORE_API_KEY=your_key_here,GHL_CLIENT_ID=your_id,GHL_CLIENT_SECRET=your_secret"
```

To use a **custom service account** (from Step 3):

```bash
gcloud run deploy sms-api ^
  --image gcr.io/nola-sms-pro/sms-api:latest ^
  --platform managed ^
  --region asia-southeast1 ^
  --service-account sms-api-run@nola-sms-pro.iam.gserviceaccount.com ^
  --allow-unauthenticated ^
  --port 8080
```

---

## Step 7: Test the endpoints

Base URL format: `https://sms-api-xxxxx-asia-southeast1.run.app`

| Action        | Method | URL |
|---------------|--------|-----|
| Send SMS      | POST   | `https://YOUR-SERVICE-URL/webhook/send_sms` |
| Receive SMS   | POST   | `https://YOUR-SERVICE-URL/webhook/receive_sms` |
| Get last payload | GET | `https://YOUR-SERVICE-URL/webhook/send_sms` |
| Status update (cron) | GET | `https://YOUR-SERVICE-URL/webhook/retrieve_status` |

The `.htaccess` in the image maps `/webhook/send_sms` to `api/webhook/send_sms.php`.

Test with curl or Postman, e.g.:

```bash
curl -X GET "https://YOUR-SERVICE-URL/webhook/send_sms"
```

---

## Step 8: (Optional) Map custom domain

To use `https://webhooks.nolacrm.io`:

1. **Cloud Run** → select **sms-api** → **Manage custom domains**.
2. **Add mapping** → choose **webhooks.nolacrm.io** (or your domain).
3. Follow the wizard: add the DNS records it shows (in your domain registrar or DNS provider).
4. After DNS propagates, traffic to `https://webhooks.nolacrm.io/webhook/send_sms` goes to your Cloud Run service.

---

## Step 9: (Optional) Schedule retrieve_status (cron)

To update SMS statuses every 5 minutes:

1. Enable **Cloud Scheduler** (if not already):
   ```bash
   gcloud services enable cloudscheduler.googleapis.com
   ```

2. Create a job that calls your Cloud Run URL:
   ```bash
   gcloud scheduler jobs create http sms-retrieve-status ^
     --schedule="*/5 * * * *" ^
     --uri="https://YOUR-SERVICE-URL/webhook/retrieve_status" ^
     --http-method=GET ^
     --location=asia-southeast1
   ```

Replace `YOUR-SERVICE-URL` with your actual Cloud Run URL. If your service requires authentication, add `--oidc-service-account-email=...` or use an invoker identity that is allowed to call the service.

---

## Summary

| Step | What you did |
|------|------------------|
| 1    | Set project `nola-sms-pro` |
| 2    | Enabled Cloud Build, Cloud Run, Container Registry |
| 3    | Granted Firestore access to the Cloud Run service account |
| 4    | Built image with `gcloud builds submit --config=cloudbuild.yaml .` |
| 5    | Deployed with `gcloud run deploy sms-api ...` |
| 6    | (Optional) Set env vars or custom service account |
| 7    | Tested `/webhook/send_sms` and `/webhook/retrieve_status` |
| 8    | (Optional) Custom domain `webhooks.nolacrm.io` |
| 9    | (Optional) Cloud Scheduler for status updates |

After this, your SMS API runs on Cloud Run and uses Firestore in **nola-sms-pro**. No Hostinger or SFTP needed; redeploy with the same `gcloud builds submit` and `gcloud run deploy` after code changes.
