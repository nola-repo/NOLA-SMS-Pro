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
*Items in this section cover the core SMS dispatch logic and GHL sync consistency.*

| Task | Category | Status |
| :--- | :--- | :--- |
| **Fix Credit Deduction Policy**: Ensure system API keys in custom fields don't bypass billing | Deductions | **Completed** |
| **Segment-Aware Trial Tracking**: Correctly count multi-segment messages in free trial | Deductions | **Completed** |
| **GHL Execution Log Enhancement**: Detailed JSON response for Workflow visibility | Sync Architecture| **Completed** |
| **Action Executed From Branding**: Standardize "Nola Web" source identification | Sync Architecture| **Completed** |
| **Native Sync Consistency**: Ensure metadata matches between `messages` and `sms_logs` | Sync Architecture| Pending |
| **Internal Deduction Debugging**: Add server-side logs to CreditManager for audit trails | Core Reliability | Pending |

---

## ⚙️ Infra / Maintenance
- [ ] GCP Cloud Scheduler job registration for all automated tasks.
- [ ] **CORS Maintenance**: Audit all future `.php` endpoints to ensure `cors.php` is included.
