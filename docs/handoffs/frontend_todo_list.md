# Full Project To-Do List — Single-Deduction Credit System

> Architecture: **Agency wallet = funding source only.** Subaccount wallet is the only wallet deducted on SMS send.

---

## 1. Planning Phase
- [x] Gather requirements for dual-wallet billing (agency & subaccount) - completed
- [x] Scan existing code structure (`CreditManager.php`, frontend apps) - completed
- [x] Draft `implementation_plan.md` architecture - completed
- [x] Create `backend_handoff_billing.md` API specification - completed
- [x] Refactor architecture to single-deduction model - completed
- [x] Update handoff doc to remove Stripe and reflect new model - completed

---

## 2. Frontend Implementation
> All frontend is fully built and type-checked (0 errors).

### Agency App (`agency/`)
- [x] `pages/Billing.tsx` — Wallet & Transactions + Credit Requests dashboard - completed
- [x] Wallet card labeled as "Agency Funding Wallet" - completed
- [x] Low-balance amber nudge (informational, non-blocking by default) - completed
- [x] **Master Balance Lock** optional toggle — when ON, blocks all subaccounts if agency hits 0 - completed
- [x] Top-Up modal with package tiles → external checkout URL - completed
- [x] Auto-Recharge config (inline toggle + dropdowns, calls `set_auto_recharge`) - completed
- [x] Gift Credits modal (agency → subaccount atomic transfer) - completed
- [x] Transaction ledger (month filter, Summary: Credits Added / Distributed Out / Auto-Recharged) - completed
- [x] Credit Requests panel (approve/deny with pending badge count) - completed
- [x] `/billing` route + `Credits & Billing` sidebar nav item - completed

### User/Subaccount App (`user/`)
- [x] Settings → Credits tab: Auto-Recharge config row - completed
- [x] Settings → Credits tab: "Request Credits from Agency" modal - completed

---

## 3. Backend Implementation
> Reference: [`backend_handoff_billing.md`](./backend_handoff_billing.md)

### Firestore
- [ ] Create `agency_wallet` collection (include `enforce_master_balance_lock` field) - pending
- [ ] Add `auto_recharge_enabled/amount/threshold` to `integrations` docs - pending

### API Endpoints
- [ ] `api/billing/agency_wallet.php` — GET, POST: `set_auto_recharge`, `set_master_lock`, `gift` - pending
- [ ] `api/billing/subaccount_wallet.php` — GET, POST: `set_auto_recharge`, `request_credits` - pending
- [ ] `api/billing/credit_requests.php` — GET, POST: `approve`, `deny` - pending
- [ ] `api/billing/transactions.php` — GET (by scope + month) - pending

### Core Credit Logic
- [ ] Update `CreditManager.php`: deduct **only** subaccount wallet on SMS send - pending
- [ ] Add `enforce_master_balance_lock` check (optional agency gating) - pending
- [ ] Add `provider_cost`, `charged`, `profit` fields to every SMS log - pending
- [ ] Upgrade `credit_transactions` schema: add `wallet_scope`, `deducted_from`, pricing fields - pending
- [ ] Auto-recharge cron script (agency and subaccount run **independently**) - pending
- [ ] Payment provider webhook — credit correct wallet on successful checkout - pending

---

## 4. Integration Testing
- [ ] CORS validation for all new `/api/billing/` endpoints - pending
- [ ] End-to-end: Top-up → webhook → wallet balance updates - pending
- [ ] End-to-end: Subaccount sends SMS → only subaccount balance decreases - pending
- [ ] End-to-end: Agency wallet at 0, master lock OFF → subaccounts still send - pending
- [ ] End-to-end: Agency wallet at 0, master lock ON → subaccounts blocked - pending
- [ ] End-to-end: Gift credits → agency balance -X, subaccount balance +X - pending
- [ ] End-to-end: Credit request → agency approves → subaccount balance +X - pending
