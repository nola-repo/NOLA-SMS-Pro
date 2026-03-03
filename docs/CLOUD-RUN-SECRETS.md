# Cloud Run Environment Variables & Secrets

Set these in **Cloud Run → sms-api → Edit & deploy new revision → Variables & secrets**.
Never commit these values to git.

## Required

| Name | Value | Used by |
|------|-------|---------|
| `WEBHOOK_SECRET` | `f7RkQ2pL9zV3tX8cB1nS4yW6` | send_sms, receive_sms, api/messages (via X-Webhook-Secret header) |

## GHL Marketplace (OAuth)

| Name | Value | Used by |
|------|-------|---------|
| `GHL_CLIENT_ID` | `69a681e82983c3ff86b3fd91-mma9cldk` | ghl_callback.php |
| `GHL_CLIENT_SECRET` | `ec7ff93f-fc76-4e16-badf-0b6a4583dac9` | ghl_callback.php |

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
