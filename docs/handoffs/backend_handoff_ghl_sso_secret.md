# Backend Handoff — GHL SSO Shared Secret Fix

**Repo:** nola-repo/NOLA-SMS-Pro → `/api/agency/ghl_sso_decrypt.php`
**Service:** `sms-api` → Cloud Run (region: `asia-southeast1`, project: `nola-sms-pro`)
**Priority:** 🔴 CRITICAL — Agency Panel is completely broken without this
**Date:** 2026-04-14

---

## What Is Broken

```
POST /api/agency/ghl_sso_decrypt.php  →  400 Bad Request

{
  "success": false,
  "error": "Failed to decrypt SSO payload. Verify your Shared Secret."
}
```

The Agency Panel successfully receives the encrypted GHL SSO payload from
GHL's postMessage API, but the backend **cannot decrypt it** because the
`GHL_SSO_SECRET` environment variable on the Cloud Run service is either:

- Not set at all (falling back to the wrong hardcoded value), OR
- Set to a stale/incorrect shared secret that doesn't match what GHL uses

---

## Root Cause

In `api/agency/ghl_sso_decrypt.php` (line 49):

```php
$sharedSecret = getenv('GHL_SSO_SECRET') ?: '4d205fb2-9c8d-4575-a43d-f2f68280decd';
```

The **hardcoded fallback** (`4d205fb2-9c8d-4575-a43d-f2f68280decd`) is a
placeholder UUID and will NEVER match GHL's real shared secret.  
If `GHL_SSO_SECRET` is not set in the Cloud Run environment, all decryption
will fail with a 400 — for every agency, every time.

---

## The Fix — One Shell Command

Set the correct `GHL_SSO_SECRET` in the Cloud Run service environment:

```powershell
gcloud run services update sms-api `
  --region asia-southeast1 `
  --set-env-vars "GHL_SSO_SECRET=YOUR_ACTUAL_GHL_SSO_SHARED_SECRET"
```

**Where to find the GHL SSO Shared Secret:**

1. Log in to GHL Developer Portal:  
   `https://marketplace.gohighlevel.com/apps` → select your app (NOLA SMS Pro)
2. Go to **App Settings** → **SSO / Custom Menu**
3. Copy the **Shared Secret** value shown there
4. Replace `YOUR_ACTUAL_GHL_SSO_SHARED_SECRET` in the command above

---

## Verify the Fix

After updating the env var, run:

```bash
curl -X POST https://smspro-api.nolacrm.io/api/agency/ghl_sso_decrypt.php \
  -H "Content-Type: application/json" \
  -d '{"encryptedPayload": "PASTE_A_REAL_ENCRYPTED_PAYLOAD_HERE"}'
```

Expected success response:
```json
{
  "success": true,
  "companyId": "kzlDzbJPrxuBhOGmp32U",
  "userId": "8qnLQPSnAxwiwRoMdBJm",
  "activeLocation": "..."
}
```

Expected failure (wrong secret) that confirms the env var is still wrong:
```json
{
  "success": false,
  "error": "Failed to decrypt SSO payload. Verify your Shared Secret."
}
```

---

## Check Current State of Cloud Run Env Vars

To see what env vars are currently set on the Cloud Run service:

```bash
gcloud run services describe sms-api \
  --region asia-southeast1 \
  --format="value(spec.template.spec.containers[0].env)"
```

Or in the GCP Console:  
`Cloud Run → sms-api → Edit & Deploy New Revision → Variables & Secrets`

---

## All Env Vars That Should Be Set (Full Reference)

While you're updating the Cloud Run service, verify ALL of these are set:

| Variable | Purpose | Where to find |
|----------|---------|---------------|
| `GHL_SSO_SECRET` | 🔴 **CRITICAL** — Decrypt GHL SSO payload | GHL Developer Portal → App → SSO settings |
| `JWT_SECRET` | Sign NOLA JWT tokens | Your own secret — should already be set |
| `GHL_CLIENT_ID` | GHL OAuth | GHL Developer Portal → App → OAuth |
| `GHL_CLIENT_SECRET` | GHL OAuth | GHL Developer Portal → App → OAuth |
| `WEBHOOK_SECRET` | Internal API auth (`f7RkQ2pL9zV3tX8cB1nS4yW6`) | Already hardcoded in nginx.conf |
| `SEMAPHORE_API_KEY` | SMS sending via Semaphore | Semaphore dashboard |

---

## No Code Changes Required

**The `ghl_sso_decrypt.php` code itself is correct.** The AES-256-CBC
OpenSSL decryption algorithm matches GHL's CryptoJS implementation exactly.
This is purely a **missing/wrong environment variable** on the Cloud Run service.

---

## What Happens After the Fix

Once `GHL_SSO_SECRET` is correctly set:

1. Agency panel loads inside GHL iframe
2. Frontend sends `REQUEST_USER_DATA` to GHL parent frame
3. GHL responds with `REQUEST_USER_DATA_RESPONSE` (encrypted payload)
4. Frontend posts payload to `POST /api/agency/ghl_sso_decrypt.php`
5. Backend decrypts → returns `{ companyId: "kzlDzbJPrxuBhOGmp32U" }` ✅
6. Frontend calls `POST /api/agency/ghl_autologin` with the correct companyId
7. Backend issues JWT for that agency ✅
8. Subaccounts load correctly ✅

---

## Proof of Working Frontend

The frontend fix is already deployed and confirmed working to this point:

```
[NOLA SMS] 📤 Sent REQUEST_USER_DATA to GHL parent frame
[NOLA SMS] 🔒 SSO payload received — decrypting...
POST /api/agency/ghl_sso_decrypt.php  →  400  ← ONLY THIS STEP IS BROKEN
[NOLA SMS] ❌ Timeout: No company ID received after 10s.
```

The payload is being received and sent correctly. Only the missing env var
on the Cloud Run backend is blocking resolution.
