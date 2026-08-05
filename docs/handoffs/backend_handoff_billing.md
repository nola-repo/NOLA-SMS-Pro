# Backend Handoff — Credit & Billing System (Single-Deduction Architecture)

**Scope:** Agency-level funding wallet, single-wallet SMS deduction, credit distribution, auto-recharge, gifting, and credit requests.  
**Stack:** PHP + Firestore (follow `CreditManager.php` style)  
**Priority:** High — frontend is fully built and wired.

> [!IMPORTANT]
> **Architecture Principle:** A single SMS send results in ONLY ONE wallet deduction — from the subaccount wallet. The agency wallet is a **funding source only**. It is never deducted during message dispatch unless the optional Master Balance Lock is enabled.

---

## 1. Wallet Roles

| Wallet | Purpose | Deducted on SMS? |
|---|---|---|
| **Agency Wallet** | Funding source. Top-up, credit distribution to subaccounts. | ❌ No (unless Master Lock enabled) |
| **Subaccount Wallet** | Usage wallet. Credits are consumed here per SMS. | ✅ Yes — always |

---

## 2. Firestore Schema

### 2a. New Collection — `agency_wallet`

```
agency_wallet/
  {agency_id}/
    balance: number                    // credit units
    auto_recharge_enabled: boolean
    auto_recharge_amount: number       // credits to add on trigger
    auto_recharge_threshold: number    // trigger when balance drops below this
    enforce_master_balance_lock: boolean  // optional: block all sends if agency hits 0
    updated_at: timestamp
```

### 2b. New Collection — `credit_requests`

```
credit_requests/
  {request_id}/
    agency_id: string
    location_id: string
    location_name: string
    amount: number
    status: "pending" | "approved" | "denied"
    note: string
    created_at: timestamp
    resolved_at: timestamp | null
    resolved_by: string
```

### 2c. Update existing `integrations/{location_id}` docs

Add alongside existing `credit_balance`:
```
  auto_recharge_enabled: boolean
  auto_recharge_amount: number
  auto_recharge_threshold: number
```

---

## 3. New API Files

> All files go in `api/billing/`. Follow the existing CORS/auth header pattern.

---

### `api/billing/agency_wallet.php`

**GET** — Returns agency wallet state.
```json
{
  "balance": 1500,
  "auto_recharge_enabled": true,
  "auto_recharge_amount": 500,
  "auto_recharge_threshold": 100,
  "enforce_master_balance_lock": false,
  "updated_at": "2026-04-20T00:00:00Z"
}
```

> **Note on Top-ups:** The frontend never calls an API for top-ups. It opens external checkout URLs per package, passing `?agency_id={id}&scope=agency`. Your payment provider webhook should credit `agency_wallet.balance` on successful purchase.

**POST — `action=set_auto_recharge`**
```json
// Request
{ "agency_id": "X", "enabled": true, "amount": 500, "threshold": 100 }
// Response
{ "success": true }
```

**POST — `action=set_master_lock`**
```json
// Request
{ "agency_id": "X", "enabled": true }
// Response
{ "success": true }
```

**POST — `action=gift`** — Transfer credits from agency wallet to a subaccount.
```json
// Request
{ "agency_id": "X", "location_id": "abc123", "amount": 100, "note": "Monthly allocation" }
// Response
{ "success": true, "agency_balance": 1400, "subaccount_balance": 350 }
```
> Use a Firestore **transaction** to atomically deduct from `agency_wallet.balance` and add to `integrations/{location_id}.credit_balance`. Log under `credit_transactions` with `wallet_scope: "agency"` (debit) and `wallet_scope: "subaccount"` (credit).

---

### `api/billing/subaccount_wallet.php`

**GET** — Returns subaccount wallet state (reads from `integrations/{location_id}`).
```json
{
  "balance": 249,
  "auto_recharge_enabled": false,
  "auto_recharge_amount": 250,
  "auto_recharge_threshold": 25,
  "updated_at": "2026-04-20T00:00:00Z"
}
```

> **Note on Top-ups:** Same as agency — external checkout link with `?location_id={id}`. Payment webhook credits `integrations/{location_id}.credit_balance`.

**POST — `action=set_auto_recharge`**
```json
// Request
{ "location_id": "abc123", "enabled": true, "amount": 250, "threshold": 25 }
// Response
{ "success": true }
```

**POST — `action=request_credits`** — Subaccount requests credits from agency.
```json
// Request
{ "location_id": "abc123", "amount": 200, "note": "Running low, campaign this weekend" }
// Response
{ "success": true, "request_id": "req_xyz" }
```
> Creates a new doc in `credit_requests` with `status: "pending"`.

---

### `api/billing/credit_requests.php`

**GET** — Agency lists all credit requests.

Query params: `?agency_id=X&status=pending` (status optional, default = all)

```json
{
  "requests": [
    {
      "request_id": "req_xyz",
      "location_id": "abc123",
      "location_name": "J&K Car Rentals",
      "amount": 200,
      "note": "Running low",
      "status": "pending",
      "created_at": "2026-04-20T10:00:00Z"
    }
  ]
}
```

**POST — `action=approve`**
```json
// Request
{ "request_id": "req_xyz", "agency_id": "X" }
// Response
{ "success": true, "agency_balance": 1300, "subaccount_balance": 449 }
```
> Firestore transaction: deduct from agency wallet, add to subaccount, set `status → "approved"`, log both in `credit_transactions`.

**POST — `action=deny`**
```json
// Request
{ "request_id": "req_xyz", "agency_id": "X" }
// Response
{ "success": true }
```

---

### `api/billing/transactions.php`

**GET** — Paginated transaction log. Agency wallet only tracks funding events, not SMS usage.

Query params:
- `?scope=agency&agency_id=X&month=2026-04`
- `?scope=subaccount&location_id=abc123&month=2026-04`

```json
{
  "transactions": [
    {
      "id": "txn_001",
      "type": "sms_usage",
      "deducted_from": "subaccount",
      "subaccount_id": "abc123",
      "agency_id": "X",
      "amount": -1,
      "balance_after": 248,
      "provider_cost": 0.02,
      "charged": 0.05,
      "profit": 0.03,
      "provider": "telnyx",
      "description": "SMS to +639171234567",
      "timestamp": "2026-04-20T10:00:00Z"
    }
  ],
  "total": 42,
  "page": 1
}
```

> **Transaction types by wallet scope:**

| Event | type | wallet_scope | amount sign |
|---|---|---|---|
| Top-up purchase | `top_up` | agency | positive |
| Auto-recharge | `auto_recharge` | agency | positive |
| Agency gifted to subaccount | `credit_distribution` | agency | negative |
| Agency gifted to subaccount | `gift_received` | subaccount | positive |
| Credit request approved | `request_approved` | subaccount | positive |
| SMS sent | `sms_usage` | subaccount | negative |

---

## 4. Updated SMS Send Handler (`CreditManager.php`)

```php
function sendSms($location_id, $agency_id, $message): array {
    $subBalance = getSubaccountBalance($location_id);

    // Block if subaccount has no credits
    if ($subBalance <= 0) {
        return ['success' => false, 'error' => 'insufficient_credits',
                'subaccount_balance' => $subBalance];
    }

    // Optional master balance lock check
    $agencyLock = getAgencyMasterLock($agency_id); // reads enforce_master_balance_lock
    if ($agencyLock) {
        $agencyBalance = getAgencyBalance($agency_id);
        if ($agencyBalance <= 0) {
            return ['success' => false, 'error' => 'agency_master_lock',
                    'agency_balance' => $agencyBalance];
        }
    }

    // Deduct ONLY from subaccount wallet (atomic Firestore transaction)
    $cost = 1; // credits per SMS
    deductSubaccountBalance($location_id, $cost);

    // Log with full pricing metadata for profit tracking
    logTransaction([
        'type'          => 'sms_usage',
        'wallet_scope'  => 'subaccount',
        'deducted_from' => 'subaccount',
        'location_id'   => $location_id,
        'agency_id'     => $agency_id,
        'amount'        => -$cost,
        'balance_after' => $subBalance - $cost,
        'provider_cost' => 0.02,   // actual cost from provider
        'charged'       => 0.05,   // what client is billed
        'profit'        => 0.03,   // derived field
        'provider'      => 'telnyx',
        'timestamp'     => now(),
    ]);

    return ['success' => true];
}
```

**Error codes returned to frontend:**
- `insufficient_credits` → "Your account has no credits. Please top up or request credits from your agency."
- `agency_master_lock` → "Sending is temporarily paused by your agency. Please contact your administrator."

---

## 5. Auto-Recharge Cron (`api/billing/auto_recharge_cron.php`)

Runs every 15 minutes via Cloud Scheduler:

1. Query `integrations` docs where `auto_recharge_enabled = true AND credit_balance < auto_recharge_threshold`
2. For each, trigger payment charge for `auto_recharge_amount` (via payment provider)
3. On success: add credits to subaccount, log `type=auto_recharge`
4. Separately: query `agency_wallet` docs where same condition, recharge agency wallet

Both operate completely **independently** of each other.

---

## 6. Transaction Log Schema (`credit_transactions` collection)

Reuse the existing collection. Add/ensure these fields:

```
wallet_scope: "agency" | "subaccount"   // who was deducted/credited
type: string                             // see type table above
deducted_from: "subaccount" | "agency"  // physical deduction location
provider_cost: number                    // real cost to you (for sms_usage)
charged: number                          // what you charge the client
profit: number                           // charged - provider_cost
provider: "telnyx" | "twilio" | null
balance_after: number
```

---

## Summary Checklist for Backend Team

### Firestore
- [ ] Create `agency_wallet` collection (with `enforce_master_balance_lock` field)
- [ ] Add `auto_recharge_*` fields to `integrations` docs

### API Files
- [ ] `api/billing/agency_wallet.php` — GET, POST: `set_auto_recharge`, `set_master_lock`, `gift`
- [ ] `api/billing/subaccount_wallet.php` — GET, POST: `set_auto_recharge`, `request_credits`
- [ ] `api/billing/credit_requests.php` — GET, POST: `approve`, `deny`
- [ ] `api/billing/transactions.php` — GET (by scope + month)

### Core Logic
- [ ] Update `CreditManager.php` — deduct **only subaccount** wallet on SMS send
- [ ] Add optional `enforce_master_balance_lock` check in send handler
- [ ] Add profit/cost metadata to every SMS transaction log
- [ ] Update `credit_transactions` schema with `wallet_scope`, `provider_cost`, `charged`, `profit`
- [ ] Create auto-recharge cron script (agency and subaccount run independently)
- [ ] Payment provider webhook to apply credits on successful purchase

*Frontend: Fully built and ready to connect once endpoints are live.*
