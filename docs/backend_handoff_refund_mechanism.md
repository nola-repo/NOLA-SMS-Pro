# Backend Handoff: SMS Credit Refund Mechanism

This document details how and when SMS credits are refunded on the backend when an outbound message fails to send.

---

## 1. Credit Flow Model (Pre-Deduction)

To prevent credit overruns and guarantee that tenants have sufficient balance before sending, NOLA SMS Pro uses a **pre-deduction model**:
1. When a send request is initiated, the system calculates the required credits based on character count (GSM-7 segments vs Unicode UCS-2 segments) and the number of recipients.
2. The credits are immediately deducted from the subaccount's paid wallet (or the free trial allocation count is incremented) using an atomic Firestore transaction (`CreditManager::deduct_subaccount_only` or `CreditManager::deduct_agency_and_subaccount`).
3. Only after the balance is successfully updated in Firestore does the backend invoke the SMS gateway (`SmsGatewayService::send`).
4. If the gateway rejects the send, a compensating transaction (**Refund**) must be triggered to restore the credits.

---

## 2. Refund Scenarios & Trigger Statuses

The automatic refund system handles failures differently depending on the endpoint through which the SMS was sent:

### Scenario A: GHL Custom Provider (`api/webhook/ghl_provider.php`)

When an SMS is sent via the GHL Custom Provider flow:
- The backend attempts to send the message using the active provider gateway.
- If the gateway rejects the message immediately during submission (`!$gatewayAccepted`), the backend handles the failure:
  - **Paid Sends**: If the send consumed paid credits (i.e. `$usingFreeCredits` is false), the backend automatically triggers a refund:
    ```php
    $creditManager->add_credits(
        $account_id,
        $required_credits,
        $messageId ?? 'refund_ghl_prov',
        'Refund — SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
        'refund'
    );
    ```
  - **Free Trial Sends**: If the send was covered by free trial credits, no wallet transaction is recorded. No paid refund is issued, and the trial counter is not decremented.
  - **GHL Notification**: The script calls the GHL status sync API with `'Failed'` so the message status badge updates correctly in the GHL dashboard.

### Scenario B: Standalone Web App / Bulk Sends (`api/webhook/send_sms.php`)

When a message is sent from the standalone React composer or general GHL workflows:
- **Pre-Deduction**: Credits are deducted prior to sending.
- **Gateway Submission**: The backend calls `$gateway->send(...)`.
- **Pre-Submission Failure**: If the gateway throws an exception or rejects the message, the script logs the error and updates the Firestore message document to `Failed`.
- **CRITICAL GAP**: Unlike the GHL Provider flow, `send_sms.php` **does not** contain any compensating refund code. If the gateway rejects a message pre-submission in this endpoint, the credits are **not** automatically returned to the user's wallet.

### Scenario C: Post-Submission Failures / Cron Polling (`StatusSync.php`)

If a message is successfully accepted by the gateway (returning a provider reference ID) but fails to reach the destination device:
- The `retrieve_status.php` cron (invoking `StatusSync::runSync`) polls the gateway API for all messages in a non-final state (`Sending`, `Queued`, `Pending`).
- If the provider reports a final failed status (`failed`, `expired`, `rejected`, `undelivered`), the message state in Firestore is finalized as `Failed` via `StatusSync::finalize`.
- **NO REFUND**: Once a message has been successfully accepted by the gateway provider, it is **never** refunded. The credits remain consumed, as providers charge for transmission attempts regardless of whether the handset successfully receives the message.

---

## 3. Firestore Ledger Schema for Refunds

Refunds are recorded as audit logs in the `credit_transactions` collection in Firestore.

A typical refund transaction has the following fields:
```text
credit_transactions/{transactionId}
├── transaction_id: string          // Generated Document ID
├── account_id: string              // Subaccount doc ID (e.g. ghl_xxx)
├── wallet_scope: "subaccount"
├── type: "refund"
├── amount: number                  // Positive integer (e.g. +1, +2) representing returned credits
├── balance_after: number           // Wallet balance snapshot after refund
├── reference_id: string            // Usually matches the failed message ID (or 'refund_ghl_prov')
├── description: string             // "Refund — SMS failed to send (Semaphore rejected)"
└── created_at: timestamp           // Server timestamp
```

---

## 4. Current Gaps & Recommendations

1. **Implement Refund in `send_sms.php`**:
   - Add a catch block and gateway acceptance check in `api/webhook/send_sms.php` similar to `ghl_provider.php`.
   - If the gateway fails to accept the message during submission, run a compensating transaction to refund the deducted credits to the subaccount/agency wallet.
2. **Review Multi-Recipients Refund Policy**:
   - If a bulk send to 10 recipients has 3 failures during gateway submission, the system should ideally refund `3 * segments` credits. Ensure partial failures are correctly tallied and refunded.
