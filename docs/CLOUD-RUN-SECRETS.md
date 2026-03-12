# Cloud Run Environment Variables & Secrets

Set these in **Cloud Run → sms-api → Edit & deploy new revision → Variables & secrets**.
Never commit these values to git.

## Required

| Name | Value | Used by |
|------|-------|---------|
| `X-Webhook-Secret` | `f7RkQ2pL9zV3tX8cB1nS4yW6` | send_sms, receive_sms, api/messages (via X-Webhook-Secret header) |

## GHL Marketplace (OAuth)

| Name | Value | Used by |
|------|-------|---------|
| `GHL_CLIENT_ID` | `69aa6cc3412b25467476d5de-mmehrtt9` | ghl_callback.php |
| `GHL_CLIENT_SECRET` | `dfc4380f-6132-49b3-8246-92e14f55ee78` | ghl_callback.php |

> [!WARNING]
> **Credential Synchronization Required**
> The `GHL_CLIENT_ID` and `GHL_CLIENT_SECRET` set here in Cloud Run **MUST** match the hardcoded values in the Frontend code (Managed by Raely).
> If they do not match, the "No integration found" error will persist.

**GHL Redirect URI (set in Marketplace App Auth):**  
`https://smspro-api.nolacrm.io/oauth/callback`  

## Semaphore (SMS)

Currently in `api/webhook/config.php`. For production, move to env:

| Name | Value | Used by |
|------|-------|---------|
| `SEMAPHORE_API_KEY` | *(your Semaphore API key)* | send_sms.php |

---

## Header for API calls (Web UI, GHL, etc.)

Send this header with every request to protected endpoints:

- `X-Webhook-Secret: <value of WEBHOOK_SECRET>`
