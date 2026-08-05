# Backend Handoff: Simultaneous Agency & Subaccount Credit Deduction

## 🎯 Objective
Currently, when a subaccount sends an SMS, `CreditManager.php` accurately handles credit deduction from the subaccount's wallet. However, the overarching Agency's master wallet (`agency_wallets/{companyId}`) is **not** being deducted. 
This update will modify the backend so that every outbound SMS natively processes a dual-deduction:
1. Deduct retail credits from the **Subaccount's wallet**.
2. Deduct wholesale credits (or native cost) from the **Agency's master wallet**.

---

## 🏗️ Proposed Changes

### 1. `backend/api/services/CreditManager.php`
**Add Dual-Deduction Transaction Logic:**
We must ensure that the deduction from *both* wallets occurs atomically (within a single Firestore Transaction) so a failure in one rolls back the other.
- **[NEW FUNCTION]** `deduct_agency_and_subaccount($location_id, $company_id, $subaccount_amount, $agency_amount, $reference_id, $description)`
  - Run a single `$this->db->runTransaction()`.
  - **Read phase**: Load both the subaccount document and the `agency_wallets/{companyId}` document. Ensure both have sufficient balances.
  - **Write phase**: Save the new decayed balances for both documents.
  - Create two separate transaction logs in the `credit_transactions` sub-collections or main collection:
    - One targeting `account_id` (the subaccount) with type `deduction` for the `$subaccount_amount`.
    - One targeting `company_id` (the agency) with type `agency_deduction` for the `$agency_amount`.
- *Note:* This prevents race conditions and mismatched balances should a network failure occur halfway through sending an SMS batch.

### 2. `backend/api/webhook/send_sms.php`
**Implement the Dual-Deduction Call:**
- Around **Line 193** where we enforce the Master Balance Lock (`enforce_master_balance_lock`), we already fetch `$companyId`. Keep this available.
- Around **Line 326**, replace the standard `$creditManager->deduct_credits()` call with the new atomic function.
```php
// Determine credit requirements
$required_subaccount_credits = $required_credits; 

// If the system uses separate wholesale/retail, compute the Agency burden here
// (e.g. by checking admin_config/global_pricing API rates)
$required_agency_credits = $required_credits; 

if ($companyId) {
    // Agency is present: Deduct from both Subaccount and Agency
    $creditManager->deduct_agency_and_subaccount(
        $account_id,
        $companyId,
        $required_subaccount_credits,
        $required_agency_credits,
        $batch_id ?? ('single_' . bin2hex(random_bytes(4))),
        "SMS sent to $num_recipients recipients"
    );
} else {
    // Solo subaccount: Default deduction
    $creditManager->deduct_credits(
        $account_id,
        $required_subaccount_credits,
        $batch_id ?? ('single_' . bin2hex(random_bytes(4))),
        "SMS sent to $num_recipients recipients"
    );
}
```

### 3. `backend/api/webhook/ghl_provider.php` (If applicable)
**Ensure Secondary Webhooks Conform:**
If `ghl_provider.php` manually handles deductions instead of acting as a passthrough, ensure it also leverages the new `deduct_agency_and_subaccount()` capability where `$companyId` is defined.

---

## 🧪 Verification & QA Testing
1. Send an SMS through the system (using a test GHL workflow or manual payload) associated with a known `location_id` and `company_id`.
2. Verify in Firestore:
   - `integrations/ghl_{location_id}` credit_balance decayed by the amount.
   - `agency_wallets/{companyId}` balance decayed by the amount.
3. Verify in Firestore `credit_transactions`:
   - A single outbound webhook generated *two* transaction ledger logs (one for the agency, one for the subaccount).
4. Simulate a failure (e.g., set Agency balance to 1, but trigger a blast of 5 SMS). Confirm the transaction rejects atomically and neither wallet gets falsely charged, throwing the `402 Agency Master Lock` or `Insufficient Credits` HTTP code.
