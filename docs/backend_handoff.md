# Backend Handoff — NOLASMSPro 500 Fix

**Date:** 2026-03-17  
**From:** Raely (Frontend)  
**To:** David (Backend)

---

## 🔴 Critical: Production 500 Error on `/api/sms`

**Root Cause:** `api/webhook/config.php` still listed `NOLACRM` as the default sender in `SENDER_IDS`. When the frontend sends `NOLASMSPro` (new system default), the backend validates it against this list — and since `NOLASMSPro` wasn't in the list, it silently fell back to `NOLACRM`. However the Semaphore account may not have `NOLACRM` registered anymore, causing the 500.

**Fix Applied Locally** in `api/webhook/config.php`:

```diff
-    'SENDER_IDS' => [
-        'NOLACRM',
-        'NOLASMS',
-        'BRANCH2'
-    ],
+    'SENDER_IDS' => [
+        'NOLASMSPro',
+    ],
```

**→ ACTION REQUIRED: Redeploy the Cloud Run backend to push this change to production.**

---

## Other Backend Changes (already in local repo)

| File | Change |
|------|--------|
| `api/sender-requests.php` | New endpoint — handles `GET` (list) and `POST` (create) sender ID requests to `sender_requests` Firestore collection |
| `api/account-sender.php` | New endpoint — `GET` returns `approved_sender_id`, `semaphore_api_key`, `free_usage_count`, `system_default_sender`; `POST` saves API key |
| `api/account.php` | New endpoint — returns `location_name` and `approved_sender_id` for the Settings account section |
| `api/webhook/send_sms.php` | Dynamically fetches approved sender + API key from `accounts/{location_id}` in Firestore; enforces 10-message free tier limit |
| `api/conversations.php` | Bug fix — `location_id` now validated on `DELETE` requests; cascading deletion of messages + sms_logs |

## Frontend ↔ Backend Contract Summary

| Endpoint | Method | Used By |
|----------|--------|---------|
| `/api/sender-requests` | GET | Settings page — list user's sender requests |
| `/api/sender-requests` | POST | Sender Request Modal — submit new request |
| `/api/account-sender` | GET | SenderSelector, Composer, Settings — get config |
| `/api/account-sender` | POST | Settings — save Semaphore API key |
| `/api/account` | GET | Settings account section — get location name |

All requests send `X-GHL-Location-ID` header for multi-tenancy. The `GET /api/account-sender` response **must** be wrapped as:
```json
{ "status": "success", "data": { "approved_sender_id": null, "semaphore_api_key": null, "free_usage_count": 0, "system_default_sender": "NOLASMSPro" } }
```
