# Backend Handoff — GHL Provider SMS Toggle Fix

*Date*: April 11, 2026
*Priority*: High
*Component*: Backend Webhooks (`ghl_provider.php`)

---

## 1. Issue Overview

A critical security/authorization lapse was identified where subaccounts could continue sending SMS messages through the **GoHighLevel Conversations Tab**, even when their agency explicitly turned off SMS capabilities via the `toggle_enabled` flag in the NOLA SMS Pro Agency Dashboard.

*   `send_sms.php` (used by campaigns, frontend UI, and workflows) correctly checked the `toggle_enabled` flag.
*   `ghl_provider.php` (used strictly by GoHighLevel's native conversation chatbox provider) was missing the toggle check entirely, allowing unauthorized SMS bypass.

## 2. Changes Made

### `backend/api/webhook/ghl_provider.php`
Injected the authentication block right before loading the integration config to verify the agency toggle status.

**Added the following check:**
```php
// ── Check Agency Toggle ─────────────────────────────────────────────────────
if (!isset($db)) {
    $db = get_firestore();
}

$tokenRef = $db->collection('ghl_tokens')->document($locationId);
$tokenSnap = $tokenRef->snapshot();
$tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

if (!$toggleEnabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
}
```
*If disabled, this endpoint now immediately rejects the outbound action with a 403 Forbidden.*

---

## 3. Deployment Notes

- Deploy the updated `ghl_provider.php` to the Cloud Run backend (`api/webhook/ghl_provider.php`).
- Because GHL displays any non-200 provider responses in their conversational UI, users who attempt to send a message while toggled off will now see the error message indicating it has been disabled.

---

## 4. Default Toggle State

**Is the toggle turned ON by default right after installation?**
**Yes.** 

1. **During Installation (`ghl_callback.php`)**: When the app finishes OAuth correctly, it inserts the location into the `ghl_tokens` collection with `'toggle_enabled' => true`.
2. **Fallback Execution**: In the newly added code block (and in `send_sms.php`), if the `toggle_enabled` value is somehow missing from the database, it safely falls back to `true` (meaning the service functions by default until explicitly shut off).
