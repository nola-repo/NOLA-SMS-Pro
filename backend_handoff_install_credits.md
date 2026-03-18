# Backend Handoff: Auto-Provision 10 Free Credits on GHL Installation

## Overview

When a new location installs NOLA SMS Pro via the GoHighLevel Marketplace, we need to **automatically provision 10 free SMS credits** to their account. The OAuth callback (`ghl_callback.php`) already stores the GHL tokens in the `ghl_tokens` Firestore collection. This handoff defines the additional step to provision credits at install time.

---

## Firestore Data Model

### `integrations` collection

Each location has a document keyed by `ghl_{locationId}` (sanitized, replacing non-alphanumeric chars with `_`). This is the same document used by `account-sender.php`.

**Fields relevant to credits:**

| Field | Type | Description |
|---|---|---|
| `free_usage_count` | integer | Number of SMS messages sent using the shared NOLA sender. Initially `0`. |
| `free_credits_total` | integer | Total free credits provisioned. Set to `10` on first install. |
| `approved_sender_id` | string\|null | The custom sender ID once approved. `null` by default. |
| `system_default_sender` | string | Always `"NOLASMSPro"`. Read-only constant used by frontend. |
| `installed_at` | timestamp | When the location first installed the app. |
| `location_id` | string | The GHL location ID. |

---

## Required Change in `ghl_callback.php`

After the existing Firestore token save (line ~119–133), **add a call to provision credits** if the integration document does not already exist.

### Pseudocode Logic

```php
$docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locationId);
$integrationRef = $db->collection('integrations')->document($docId);
$integrationDoc = $integrationRef->snapshot();

if (!$integrationDoc->exists()) {
    // First-time install — provision 10 free credits
    $integrationRef->set([
        'location_id'           => $locationId,
        'location_name'         => $locationName,
        'free_credits_total'    => 10,
        'free_usage_count'      => 0,
        'approved_sender_id'    => null,
        'semaphore_api_key'     => null,
        'nola_pro_api_key'      => null,
        'installed_at'          => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        'updated_at'            => new \Google\Cloud\Cloud\Timestamp(new \DateTime()),
    ]);
} else {
    // Re-install: preserve existing credits, just update tokens/name
    $integrationRef->set([
        'location_name' => $locationName,
        'updated_at'    => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);
}
```

> [!IMPORTANT]
> Use `['merge' => true]` on re-installs so existing credits and sender configuration are NOT reset.

---

## How Credits Are Consumed

In `send_sms.php`, when a message is sent using the shared `NOLASMSPro` sender (i.e., `approved_sender_id` is `null`):

1. Read `integrations/{docId}.free_usage_count` and `free_credits_total`.
2. If `free_usage_count >= free_credits_total`, **block the send** and return an error like `{"error": "free_credits_exhausted"}`.
3. If allowed, after successful send, increment `free_usage_count` by 1:

```php
$integrationRef->set([
    'free_usage_count' => $currentUsage + 1,
    'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
], ['merge' => true]);
```

> [!NOTE]
> This logic should be bypassed if `approved_sender_id` is set — in that case, credit tracking is handled by the paid plan.

---

## Document to Modify

- **File**: `NOLA-SMS-Pro-Backend/ghl_callback.php`
  - Add the `integrations` provisioning block after the `ghl_tokens` write.

- **File**: `NOLA-SMS-Pro-Backend/api/webhook/send_sms.php`
  - Add free credit check + increment when using shared sender.

---

## Frontend Expectations

The `account-sender.php` GET endpoint already returns `free_usage_count`. The frontend reads it via `fetchAccountSenderConfig()` in `src/api/senderRequests.ts`. No frontend changes needed for the basic free credit display — it reads `free_usage_count` and compares it to `10` (hardcoded as `free_credits_total` for now).

If you want the frontend to read `free_credits_total` dynamically, add it to the `account-sender.php` GET response:

```php
'free_credits_total' => $data['free_credits_total'] ?? 10,
```

---

## Testing Checklist

- [ ] Install app via Marketplace link → verify `integrations/ghl_{locationId}` document created with `free_credits_total: 10` and `free_usage_count: 0`
- [ ] Re-install → verify existing `free_usage_count` is preserved (not reset)
- [ ] Send 10 messages using shared sender → verify 11th is blocked with `free_credits_exhausted` error
- [ ] Set `approved_sender_id` → verify free credit check is bypassed
