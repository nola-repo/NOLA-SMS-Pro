# Frontend Handoff: SMS Gateway Updates & Sender ID Handling

## Executive Summary

Recent backend updates have hardened SMS delivery, cURL timeout resilience, credit protection, and Sender ID resolution. No breaking API changes were introduced. This document outlines the backend updates and provides guidance for the frontend team regarding `sendername` payload parameters.

---

## 1. Backend Hardening Summary

| Area | Update Description | Impact |
|---|---|---|
| **cURL Timeout Fast-Fail** | Added `CURLOPT_CONNECTTIMEOUT = 5s` to Semaphore & UniSMS providers. | Prevents 25s+ UI hangs when SMS gateways experience network latency. Requests fail fast within 5s. |
| **Credit Auto-Refund on Timeout** | Implemented `CreditManager::refundOnTimeout()`. | Automatically refunds deducted wallet credits if a request fails due to a cURL connection timeout (since the provider never received the payload). |
| **Approved Sender ID Priority** | Updated `SenderResolver.php` resolution logic. | Subaccounts with an approved Sender ID (e.g. `JNKRENTAL`) now reliably route using their approved sender ID without requiring legacy whitelist fallbacks. |

---

## 2. Frontend Integration Guidance (`sendername` Parameter)

### Issue Identified
In manual SMS sends, passing `sendername: "NOLASMSPro"` explicitly in the request payload triggers an explicit system sender override on the backend, bypassing the subaccount's approved custom Sender ID (e.g. `JNKRENTAL`).

### Recommendations for Frontend Developers

#### Option A: Omit or send `null` for `sendername` (Recommended)
When building payload objects for manual SMS sends, omit `sendername` or set it to `null`/empty string if the user has not explicitly selected a custom sender. The backend will automatically resolve and apply the subaccount's active approved Sender ID (`approved_sender_id`).

```json
// Recommended Payload (Manual Send)
{
  "locationId": "Is3CjRqD4xzqonUZI0Eo",
  "phone": "09938905125",
  "message": "Hello Testing"
  // sendername omitted -> Backend auto-resolves to 'JNKRENTAL'
}
```

#### Option B: Pass Active Approved Sender ID
If the UI includes a Sender ID selector/dropdown, ensure the default selected value is dynamically set to the subaccount's active `approved_sender_id` (retrieved from account/integration settings) rather than defaulting to hardcoded `"NOLASMSPro"`.

```json
// Alternative Payload with Explicit Active Sender
{
  "locationId": "Is3CjRqD4xzqonUZI0Eo",
  "phone": "09938905125",
  "message": "Hello Testing",
  "sendername": "JNKRENTAL"
}
```

---

## 3. Status Badges & Error Handling

- **Connection Timeouts (5s):** The API will return standard failed responses faster. The UI should display the `FAILED` status badge as usual.
- **Credit Balance:** In the event of a network timeout, credit balances will automatically remain accurate due to backend auto-refunds. No special frontend retry logic is needed for refund handling.

---

## 4. Verification Checklist for Frontend Team

- [ ] Verify manual SMS composer does not hardcode `sendername: "NOLASMSPro"`.
- [ ] Confirm subaccount active Sender ID (e.g., `JNKRENTAL`) displays correctly in sender configuration dropdowns.
- [ ] Test manual send flow to confirm status updates to `SENT` or `FAILED` within 5 seconds under network delays.
