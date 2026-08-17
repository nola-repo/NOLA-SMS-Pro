# Frontend Handoff: UniSMS Integration

## Summary

The backend now supports UniSMS behind the existing NOLA SMS PRO API surface. The frontend must continue calling the backend only. UniSMS API keys and provider credentials must never be sent to, stored by, or displayed in the browser.

## What Stays The Same

- Send SMS through `POST /api/sms`.
- Poll send results through `GET /api/check_message_status.php?message_ids=...&location_id=...`.
- Read credits through `GET /api/credits?fresh=1&location_id=...`.
- Read messages/conversations through the existing `/api/messages`, `/api/conversations`, and `/api/bulk-campaigns` endpoints.
- Treat missing `output.message_ids` as a failed send.

## SMS Send Response

`POST /api/sms` still returns the frontend-compatible shape:

```json
{
  "status": "success",
  "message": "NOLASMSPro",
  "execution_log": "Workflow SMS sent via NOLASMSPro to Sent to 09123456789. Credits: 1.",
  "output": {
    "success": true,
    "summary": "Sent to 09123456789",
    "credits": 1,
    "location_id": "LOCATION_ID",
    "message_ids": ["msg_abc123"]
  },
  "debug_info": {
    "is_custom_provider": false,
    "is_free_trial": false,
    "gateway_errors": []
  }
}
```

## Idempotency

The backend now protects sends with an idempotency record before billing and provider dispatch.

Recommended frontend addition:

```http
Idempotency-Key: sms_<location_id>_<client_generated_uuid>
```

If the header is not sent, the backend derives a key from location, recipient list, message, sender, batch ID, and recipient key.

Possible idempotency responses:

```json
{
  "status": "error",
  "error": "duplicate_request",
  "message": "This SMS request is already being processed."
}
```

Completed duplicate requests replay the original successful response without sending or deducting credits again.

## Status Polling

`GET /api/check_message_status.php` returns:

```json
{
  "success": true,
  "results": [
    {
      "message_id": "msg_abc123",
      "status": "Sent",
      "source": "unisms"
    }
  ]
}
```

Frontend status values remain:

- `Sending`
- `Sent`
- `Failed`

## Account Sender Settings

`GET /api/account-sender.php` no longer returns raw API keys.

Expected fields:

```json
{
  "status": "success",
  "data": {
    "sender_id": "BrandName",
    "verified": true,
    "approved_sender_id": "BrandName",
    "nola_pro_api_key": null,
    "nola_pro_api_key_masked": "abc...1234",
    "nola_pro_api_key_configured": true,
    "unisms_api_key": null,
    "unisms_api_key_masked": "sk_...abcd",
    "unisms_api_key_configured": true,
    "unisms_sender_id": "BrandName",
    "provider_preference": "unisms_custom",
    "free_usage_count": 2,
    "free_credits_total": 10,
    "system_default_sender": "NOLASMSPro",
    "toggle_enabled": true
  }
}
```

Accepted `provider_preference` values:

- `system`
- `semaphore`
- `semaphore_custom`
- `unisms`
- `unisms_custom`

Use `POST /api/account-sender.php` to submit or replace keys. Do not expect the backend to echo raw keys back.

## Sender ID Requests

Sender ID requests now support an explicit provider field. This is important for UniSMS because the backend should not infer the provider from the API key prefix.

User/subaccount request:

```http
POST /api/sender-requests.php
```

```json
{
  "requested_id": "NOLA",
  "provider": "unisms",
  "purpose": "Customer appointment and follow-up messages",
  "sample_message": "Hi {{contact.first_name}}, this is NOLA confirming your appointment."
}
```

Accepted `provider` values:

- `system`
- `semaphore`
- `unisms`

`GET /api/sender-requests.php` now returns provider metadata:

```json
[
  {
    "id": "req_abc123",
    "location_id": "LOCATION_ID",
    "requested_id": "NOLA",
    "status": "pending",
    "provider": "unisms",
    "provider_preference": "unisms",
    "unisms_sender_id": "NOLA",
    "purpose": "Customer appointment and follow-up messages",
    "sample_message": "Hi {{contact.first_name}}, this is NOLA confirming your appointment.",
    "created_at": "2026-06-15 10:00:00"
  }
]
```

Admin approval:

```http
POST /api/admin_sender_requests.php
```

For UniSMS with a subaccount-owned UniSMS key:

```json
{
  "request_id": "req_abc123",
  "status": "approved",
  "provider": "unisms",
  "api_key": "UNISMS_SECRET_KEY"
}
```

Backend stores:

```json
{
  "approved_sender_id": "NOLA",
  "provider_preference": "unisms_custom",
  "unisms_sender_id": "NOLA",
  "unisms_api_key": "stored_server_side_only"
}
```

For UniSMS using the system/master UniSMS account:

```json
{
  "request_id": "req_abc123",
  "status": "approved",
  "provider": "unisms"
}
```

Backend stores:

```json
{
  "approved_sender_id": "NOLA",
  "provider_preference": "unisms",
  "unisms_sender_id": "NOLA"
}
```

For Semaphore, use `provider: "semaphore"` or omit `provider` for legacy behavior. The backend still supports old requests that do not include provider, but new frontend work should send it explicitly.

## Admin Settings

`GET /api/admin_settings.php` now includes:

```json
{
  "status": "success",
  "data": {
    "sms_provider": {
      "active_provider": "unisms",
      "unisms_configured": true,
      "unisms_api_key_masked": "sk_...abcd",
      "unisms_sender_id": "NOLASMSPro",
      "unisms_endpoint": "https://unismsapi.com/api",
      "unisms_timeout_seconds": 15,
      "failover_timeout_seconds": 8,
      "failover_log_enabled": true
    }
  }
}
```

Admin UI can update provider settings by posting either a nested `sms_provider` object or the same fields at the top level.

## Error Codes To Handle

- `invalid_phone`
- `insufficient_credits`
- `agency_master_lock`
- `rate_limit_reached`
- `sms_disabled`
- `install_blocked`
- `duplicate_request`
- `idempotency_key_conflict`
- `credit_deduction_failed`
- `provider_unavailable`

## Security Notes

- Do not store UniSMS keys in localStorage, sessionStorage, or frontend state beyond the active form field before submit.
- Never render raw provider keys after save.
- Treat `*_configured` and `*_masked` fields as display-only.
- Keep all SMS sends routed through `/api/sms`; do not call `https://unismsapi.com/api` from the browser.
