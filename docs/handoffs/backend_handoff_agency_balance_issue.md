# Backend Handoff: Missing Agency Wallet Endpoints

**Context**
The frontend Agency Dashboard expects to retrieve and manage the "Agency Funding Wallet" balance. However, the agency balance is currently not appearing as 0 credits or throwing errors because the required backend endpoint (`api/billing/agency_wallet.php`) does not exist on the server.

**Target Repository:** `NOLA-SMS-Pro`

This is a critical handoff for the backend team to build the missing `agency_wallet` endpoints.

---

## 1. Missing Endpoint Requirements

### Endpoint Name: `api/billing/agency_wallet.php`

#### GET Request
**Purpose:** Fetches the current agency wallet state (balance and auto-recharge settings).
**Query Params:** `?agency_id={agency_id}&action=balance`
**Authentication:** Validate `X-Webhook-Secret` or existing session authentication tokens.

**Expected Response Payload:**
```json
{
  "balance": 1500,
  "auto_recharge_enabled": true,
  "auto_recharge_amount": 500,
  "auto_recharge_threshold": 100,
  "enforce_master_balance_lock": false,
  "updated_at": "2026-04-21T00:00:00Z"
}
```

*Note on Top-ups:* The frontend handles Top-ups via an external checkout link. Your payment provider webhook should listen to checkout success events and credit the `balance` field in `agency_wallet`.

#### POST Request - Action: `gift`
**Purpose:** Transfers credits from the agency wallet directly into a subaccount's wallet.
**Payload:**
```json
{
  "action": "gift",
  "agency_id": "O0YXPGWM9ep2l37dgxAo",
  "location_id": "abc12345",
  "amount": 100,
  "note": "Monthly allocation"
}
```
**Expected Logic:**
1. Check if `agency_wallet/{agency_id}.balance >= amount`. If not, return error.
2. Use a Firestore transaction to:
   - Subtract `amount` from `agency_wallet/{agency_id}.balance`.
   - Add `amount` to `integrations/{location_id}.credit_balance`.
3. Log the transaction in `credit_transactions` twice (one scope `agency` deduplication, one scope `subaccount` increase).

**Expected Response Payload:**
```json
{
  "success": true,
  "agency_balance": 1400,
  "subaccount_balance": 350
}
```

#### POST Request - Action: `set_auto_recharge`
**Purpose:** Updates the agency's auto-recharge preferences.
**Payload:**
```json
{
  "action": "set_auto_recharge",
  "agency_id": "X",
  "enabled": true,
  "amount": 500,
  "threshold": 100
}
```
**Expected Response:** `{ "success": true }`

#### POST Request - Action: `set_master_lock`
**Purpose:** Toggles the platform-wide send block based on agency balance.
**Payload:**
```json
{
  "action": "set_master_lock",
  "agency_id": "X",
  "enabled": true
}
```
**Expected Response:** `{ "success": true }`

---

## 2. Firestore Storage Structure

To support the above endpoints, ensure you have provisioned the new Firestore collection:

**Collection:** `agency_wallet`
**Document ID:** `{agency_id}`
**Fields Required:**
- `balance` (number, representing total credits)
- `auto_recharge_enabled` (boolean)
- `auto_recharge_amount` (number)
- `auto_recharge_threshold` (number)
- `enforce_master_balance_lock` (boolean)
- `updated_at` (timestamp)

---

## 3. Recommended Action Steps for Backend Team

1. Create a new directory at `backend/api/billing/`.
2. Scaffold `agency_wallet.php` inside the new folder.
3. Hook up the Firestore database to retrieve from the `agency_wallet` collection.
4. Ensure POST requests properly execute atomic Firestore transactions to prevent double-spending when gifting credits natively to subaccounts.
