# Backend Roadmap & To-Do List

This document tracks the phased development of the NOLA SMS Pro backend, including the Credit & Billing System and GHL Integration Architecture.

---

## 📊 Credit & Billing System Implementation

| Feature | Phase | Status |
| :--- | :--- | :--- |
| **Phase 1: Core Wallet Infrastructure** | | |
| Firestore Setup: `agency_wallet`, `integrations.credit_balance`, `credit_requests` | Phase 1 | Completed |
| CreditManager Service: Atomic `deduct`, `add`, and `transfer` logic | Phase 1 | Completed |
| Dual-Deduction Architecture: Atomically charge Subaccount and Agency wallets | Phase 1 | Completed |
| Agency Master Lock: Ability to pause subaccount sending via agency-level gate | Phase 1 | Completed |
| **Phase 2: Wallet Management APIs** | | |
| Agency Wallet API: Balance retrieval and gift/transfer credits | Phase 2 | Completed |
| Subaccount Wallet API: Balance, auto-recharge settings, and requests | Phase 2 | Completed |
| Transactions API: Paginated history with month filtering and wallet scope | Phase 2 | Completed |
| Credit Requests API: Agency interface to approve/deny subaccount requests | Phase 2 | Completed |
| **Phase 3: Payment & Auto Funding** | | |
| Credit Top-up Logic: Automated balance updates upon successful purchase | Phase 3 | Completed |
| Auto-Recharge Cron: Detect low balances and trigger recharging | Phase 3 | Completed |
| Payment Provider Integration: Actual "Charge" implementation (Stripe/Xendit) | Phase 3 | Pending |
| Unified Payment Webhook: handle Stripe/LemonSqueezy/Xendit callbacks | Phase 3 | Pending |
| **Refine Checkout Pricing Logic**: Implement distinct rates for User vs Agency | Phase 3 | Pending |
| Cloud Scheduler: Register auto-recharge cron (every 15 min) | Phase 3 | Completed |
| **Phase 4: Admin Controls & Configuration** | | |
| Dynamic Pricing Config: Move cost/rates from code to Firestore `admin_config` | Phase 4 | Completed |
| Global Pricing UI: Admin panel to adjust margins and provider rates | Phase 4 | Completed |
| Database Optimization: Composite indexes for transactions and performance | Phase 4 | In Progress |
| **Phase 5: Notifications & Monitoring** | | |
| Low Balance Alerts: Automated Email/SMS notifications on threshold | Phase 5 | Pending |
| Auto-Recharge Failure Alerts: Notify users if charge fails | Phase 5 | Pending |
| Profit Reporting: Admin dashboard to view margins via transaction metadata | Phase 5 | Pending |

---

## 💬 Message Syncing & Deduction Reliability

| Task | Phase | Status |
| :--- | :--- | :--- |
| **Phase 1: Core Accuracy & Billing Policy** | | |
| Fix Credit Deduction Policy: Ensure system keys in custom fields don't bypass billing | Phase 1 | **Completed** |
| Multi-Subaccount Deduction Fix: Resolved empty key bypass in `send_sms.php` | Phase 1 | **Completed** |
| Segment-Aware Trial Tracking: Correctly count multi-segment messages in free trial | Phase 1 | **Completed** |
| Flexible Location ID Resolution: Support for snake_case and camelCase payloads | Phase 1 | **Completed** |
| **Phase 2: Dashboard Experience & Branding** | | |
| GHL Execution Log Visibility: Detailed JSON response with `event_details` and aliases | Phase 2 | **Completed** |
| Action Executed From Branding: Standardize "Nola Web" source identification | Phase 2 | **Completed** |
| Response Globalization: Translate error messages for end-user visibility | Phase 2 | Pending |
| **Phase 3: Integrity & Observability** | | |
| Native Sync Consistency: Ensure metadata matches between `messages` and `sms_logs` | Phase 3 | Pending |
| Internal Deduction Debugging: Add server-side logs to CreditManager for audit trails | Phase 3 | Pending |
| **Background Location ID Injection**: Automate injection in payment flows to simplify UX | Phase 3 | Pending |
| **Company Name/Mapping Audit**: Verify transaction routing between Company Name and Location ID | Phase 3 | Pending |
| **Deduplication Stability**: Prevent loops in Provider sync (Increased window to 120s + direction prefix) | Phase 3 | **Completed** |
| **Dynamic Sender ID Resolution**: Trust Admin approvals in Firestore directly (Simplified resolution) | Phase 3 | **Completed** |
| **GHL Feedback UI Fixes**: Replaced hardcoded "NOLA SMS Pro" with actual sender IDs in GHL response | Phase 3 | **Completed** |
| **Firestore Master Whitelist**: Dynamic management of system-approved senders via Admin panel | Phase 3 | **Completed** |
| **Transactions Payload Enhancements**: Injected message_body, chars, to_number, agency_name, and subaccount_name into Firestore logging context | Phase 3 | **Completed** |

---

## ⚙️ Infra / Maintenance
- [ ] GCP Cloud Scheduler job registration for all automated tasks.
- [x] **CORS Maintenance**: Audit all future `.php` endpoints to ensure `cors.php` is included.
- [x] **API Security**: Centralize `validate_api_request()` logic across all webhook entries.

---

## 🤝 Frontend Handoffs & Integrations (2026-04-21)

This section tracks alignments and verified fixes completed internally or by the frontend team.

### Credit & Billing System Alignments

| Feature | Phase | Status |
| :--- | :--- | :--- |
| **Agency Credits UI**: `get_subaccounts.php` includes `credit_balance` | Phase 2 | Completed |
| **Agency Master Balance Lock (402)**: `send_sms.php` respects lock | Phase 1 | Completed |
| **Agency Wallet Endpoint**: `agency_wallet.php` creation verified | Phase 2 | Completed |
| **Unified Admin Subaccounts Endpoint**: `admin_sender_requests.php` supports `company_id` scoping & enriched metadata | Phase 2 | Completed |
| **Transactions Logic Enhancement**: `transactions.php` & `get_credit_transactions.php` natively support `month` parameter formatting | Phase 2 | Completed |

### Admin & Auth Fixes

| Feature | Phase | Status |
| :--- | :--- | :--- |
| **Admin All Subaccounts**: `admin_sender_requests.php` includes agency data | Phase 4 | Completed |
| **Admin Users 401 Fix**: Frontend updated to pass `X-Webhook-Secret` | Phase 4 | Completed |
| **First-Run Registration Flow**: Intercept `ghl_callback.php` to capture user account, create `users` record and avoid disjointed registration | Phase 4 | Completed |
| **Login Response Update**: Include user `phone` for frontend localStorage injection | Phase 4 | Completed |
| **Checkout Form Pre-fill**: Sync user registration info to GHL Custom Values (`owner_name`, `owner_email`, `owner_phone`) during installation | Phase 4 | Completed |
