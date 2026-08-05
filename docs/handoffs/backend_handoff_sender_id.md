# Backend Handoff: Sender ID Handling

This document outlines how Sender ID is currently handled in the backend and identifies the issue where custom sender IDs are not being used for SMS delivery.

---

## 1. Current Sender ID Implementation

### Storage
- Custom sender IDs are stored in the `integrations` collection under the document ID `ghl_{locationId}`.
- Field: `approved_sender_id` (string, nullable)
- Retrieved via `GET /api/account.php` and `GET /api/account-sender.php`

### SMS Sending Logic (`api/webhook/send_sms.php`)
When sending an SMS, the backend determines which sender and API key to use based on the following priority:

1. **Custom Sender (Tier 1)**: If all conditions are met:
   - `approved_sender_id` exists in the account's integration document
   - `nola_pro_api_key` (or legacy `semaphore_api_key`) exists
   - The `sendername` in the request payload exactly matches `approved_sender_id`
   
   → Uses the custom API key and sender ID for delivery.

2. **System Sender (Tier 2/3)**: Otherwise:
   - Uses the system `SEMAPHORE_API_KEY`
   - Sender defaults to the first item in `SENDER_IDS` array ("NOLASMSPro")

### Credit Handling
- Custom sender sends always deduct from `credit_balance` (no free credits)
- System sender uses free credits first (`free_usage_count`), then paid credits

---

## 2. The Issue: Custom Sender ID Not Being Used

### ❌ Current Problem
Custom sender IDs are not being utilized because the frontend is not sending the `approved_sender_id` as the `sendername` parameter in SMS requests.

**Expected Behavior:**
- When a user has an `approved_sender_id`, the frontend should automatically use it as the default sender
- The `sendername` in the payload must exactly match `approved_sender_id` to trigger custom sender logic

**Actual Behavior:**
- Frontend likely sends a different value (e.g., "NOLASMSPro" or null) as `sendername`
- Backend falls back to system sender, ignoring the custom sender ID

### Root Cause
The condition in `send_sms.php` is strict:
```php
if ($approvedSenderId && $customApiKey && $requestedSender === $approvedSenderId) {
    // Use custom sender
}
```
If `$requestedSender !== $approvedSenderId`, it uses system sender.

---

## 3. Required Frontend Fix

### Payload Requirement
When sending SMS via `/api/webhook/send_sms`, the frontend **MUST** include:
```json
{
  "sendername": "EXACT_APPROVED_SENDER_ID"
}
```

### Frontend Logic Update
1. On app load, fetch account data via `GET /api/account.php`
2. If `approved_sender_id` is present:
   - Set it as the default selected sender in the UI
   - When sending SMS, pass `approved_sender_id` as `sendername`
3. Hide "NOLASMSPro" option from sender dropdown to enforce white-labeling

### Testing
- [ ] Verify frontend sends `approved_sender_id` as `sendername` when custom sender is selected
- [ ] Confirm SMS delivers with custom sender ID (check Semaphore logs)
- [ ] Ensure fallback to system sender works when no custom sender is configured

---

## 4. Backend Validation (Optional Enhancement)

To prevent future issues, consider adding backend validation:

```php
// In send_sms.php, after extracting $requestedSender
if ($approvedSenderId && !$requestedSender) {
    // Auto-correct: if user has custom sender but none specified, use it
    $requestedSender = $approvedSenderId;
}
```

However, this should be implemented only after confirming frontend sends the correct value.

---

*Generated for NOLA SMS Pro Backend Integration.*