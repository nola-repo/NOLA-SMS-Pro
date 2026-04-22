# Backend Roadmap & To-Do List

This document tracks the phased development of the NOLA SMS Pro backend, including the Credit & Billing System and GHL Integration Architecture.

---

## 📊 Credit & Billing System Implementation

| Feature | Phase | Status |
| :--- | :--- | :--- |
| **Phase 1: Core Wallet Infrastructure** | | |
| Firestore Setup: `agency_wallet`, `integrations.credit_balance`, `credit_requests` | Phase 1 | Completed |
| CreditManager Service: Atomic `deduct`, `add`, and `transfer` logic | Phase 1 | Completed |
| Single-Deduction Architecture: Refactoring SMS flow to charge subaccounts directly | Phase 1 | Completed |
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
| Cloud Scheduler: Register auto-recharge cron (every 15 min) | Phase 3 | Completed |
| **Phase 4: Admin Controls & Configuration** | | |
| Dynamic Pricing Config: Move cost/rates from code to Firestore `admin_config` | Phase 4 | In Progress |
| Global Pricing UI: Admin panel to adjust margins and provider rates | Phase 4 | In Progress |
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
| Deduplication Stability: Prevent loops in Provider sync (refining `ghl_sync_dedup`) | Phase 3 | Pending |

---

## ⚙️ Infra / Maintenance
- [ ] GCP Cloud Scheduler job registration for all automated tasks.
- [ ] **CORS Maintenance**: Audit all future `.php` endpoints to ensure `cors.php` is included.
- [ ] **API Security**: Centralize `validate_api_request()` logic across all webhook entries.
