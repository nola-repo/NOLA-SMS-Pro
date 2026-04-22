# SMS Pro Project Roadmap

## 🚀 Current Status
- **Core Messaging**: Working (Web UI, GHL Sync, Provider).
- **Wallet System**: "Single-Deduction" architecture implemented in core `CreditManager`.
- **Admin/Agency Dashboards**: Mostly implemented.

## 🛠️ Immediate Issues (To-Do)

### Credits & Billing Improvements
- [ ] **Fix Credit Deduction for Workflows**
    - Audit `send_sms.php` to ensure it correctly identifies paid vs free usage.
    - Ensure `deduct_subaccount_only()` is called correctly for all workflow-initiated SMS.
    - Resolve the suspicious `if (true)` logic in `send_sms.php`.
- [ ] **Fix Credit Deduction for GHL Provider (Native SMS/Conversation Tab)**
    - Refactor `ghl_provider.php` to use the new `deduct_subaccount_only()` model.
    - Remove deprecated `deduct_both_wallets()` and `deduct_credits()` calls.
    - Ensure multi-segment messages are correctly counted in trial usage.
- [ ] **Verification of Free Trial Transition**
    - Ensure that once `free_credits_total` (default 10) is reached, the system accurately transitions to paid deduction.

### GHL Integration & Logging
- [ ] **Improve Workflow Execution Logs**
    - The message body and status should be explicitly returned in a way that GHL displays it in the "Execution Logs" / "Event Details".
    - Update `send_sms.php` response structure if needed.
- [ ] **Native Sync Refinement**
    - Ensure synced messages (sent from GHL) are correctly recorded in `sms_logs` and `messages` collection with consistent metadata.

## ✅ Recently Completed
- Single-Deduction Architecture implementation.
- Admin dashboard user management (CRUD).
- Agency wallet "gift" and auto-recharge functionality.
- GHL OAuth v2 integration with auto-refresh.
