# Backend Handoff — Credit Management & Auth Fixes

**Date:** 2026-04-21  
**Scope:** 4 fixes across billing, admin panel, and SMS pipeline

---

## Fix 1 — Agency Credits Use Same Source as Admin

**Problem:** Agency subaccounts tab showed 0 credits because `get_subaccounts.php` only reads `ghl_tokens` which has no `credit_balance`. Admin panel already reads the correct `integrations` collection.

**Change:** [`backend/api/agency/get_subaccounts.php`]

For each subaccount fetched from `ghl_tokens`, we now additionally read `integrations/ghl_{locationId}` and include `credit_balance` in the response. The field name matches what `Subaccounts.tsx` already expects.

**Firestore path:** `integrations/ghl_{locationId}.credit_balance`

---

## Fix 2 — Agency Column in Admin All Subaccounts

**Problem:** Admin "All Subaccounts" table had no way to identify which agency a subaccount belongs to.

**Backend change:** [`backend/api/admin_sender_requests.php`] — `action=accounts`

Each result row now includes:
- `company_id` — from `integrations.companyId`  
- `agency_name` — resolved by reading `ghl_tokens/{companyId}` → `company_name` / `agency_name`

**Frontend change:** [`admin/src/pages/components/AdminAccounts.tsx`]

- Added **Agency** column between Account and Sender ID
- Search bar now also filters by `agency_name`

---

## Fix 3 — Agency Master Balance Lock (HTTP 402 Bug)

**Problem:** When `enforce_master_balance_lock` was enabled AND the agency wallet was at 0, subaccounts could still send because `send_sms.php` had no agency-level check. The 402 error was being thrown from elsewhere or expected but never implemented.

**Change:** [`backend/api/webhook/send_sms.php`]
Added check immediately **after** the per-subaccount toggle check, **before** rate limit and credit deduction:

```php
// Reads agency wallet from: agency_wallets/{companyId}
if ($masterLockEnabled && $agencyBalance <= 0) {
    http_response_code(402);
    echo json_encode([
        'status'         => 'error',
        'error'          => 'agency_master_lock',
        'message'        => 'Sending is temporarily paused by your agency...',
        'agency_balance' => $agencyBalance,
    ]);
    exit;
}
```

**Priority order in send_sms.php:**
1. Maintenance mode (503)
2. Subaccount toggle disabled (403)
3. ✅ **Agency master lock** (402) ← new
4. Rate limit (403)
5. Credit deduction (403)

**Firestore paths read:**
- `ghl_tokens/{companyId}.enforce_master_balance_lock`  ← comes from Billing page toggle → `agency_wallets.php` saves to `agency_wallets/{companyId}`
- `agency_wallets/{companyId}.balance`

> [!IMPORTANT]
> **`billing/agency_wallet.php` was missing entirely.** Created at [`backend/api/billing/agency_wallet.php`]. This endpoint is required for Billing.tsx to function — without it every wallet fetch fails silently (catches the error and renders 0 balance).

**Also fixed in `Billing.tsx`:** `fetchWallet` now calls `setMasterLock(data.enforce_master_balance_lock ?? false)` on load, so the toggle reflects the saved state in Firestore.

**Firestore paths read:**
- `ghl_tokens/{companyId}.enforce_master_balance_lock`  ← comes from Billing page toggle → `agency_wallets.php` saves to `agency_wallets/{companyId}`
- `agency_wallets/{companyId}.balance`

---

## Fix 4 — Admin Users 401 Unauthorized

**Problem:** `AdminUsersManagement.tsx` was calling `/api/admin_users.php` without the `X-Webhook-Secret` header. The backend `validate_api_request()` returned 401 immediately, causing the frontend to fall back to mock data.

**Change:** [`admin/src/pages/components/AdminUsersManagement.tsx`]

Added constants at the top of the file:
```ts
const USERS_API = '/api/admin_users.php';
const WEBHOOK_SECRET = 'f7RkQ2pL9zV3tX8cB1nS4yW6';
const AUTH_HEADERS = { 'Content-Type': 'application/json', 'X-Webhook-Secret': WEBHOOK_SECRET };
```

All 5 fetch calls (GET, POST create, POST reset_password, POST toggle_status, DELETE) now use `USERS_API` and `AUTH_HEADERS`.

No backend change required — `admin_users.php` was already correctly implemented.

---

## Fix 5 — Created Missing `agency_wallet.php` Endpoint

**Problem:** `billing/agency_wallet.php` did not exist. `Billing.tsx` calls this endpoint for every wallet operation — balance fetch, master lock toggle, auto-recharge, and gift credits. Without it the page silently fell back to mock data (balance = 0).

**[NEW] [`backend/api/billing/agency_wallet.php`]**

| Method | Action | Description |
|--------|--------|-------------|
| GET | — | Returns balance, master lock, auto-recharge settings |
| POST | `set_master_lock` | Toggles `enforce_master_balance_lock` on `agency_wallets/{id}` |
| POST | `set_auto_recharge` | Saves auto-recharge enabled/amount/threshold |
| POST | `gift` | Atomic transfer: deducts from agency wallet, adds to subaccount `integrations.credit_balance` |
| POST | `add_balance` | Webhook from payment processor — adds credits to agency wallet |

**Firestore collection:** `agency_wallets/{companyId}`

---

## Verification Checklist

| Test | Expected |
|------|----------|
| Agency → Subaccounts tab | Credits column shows actual balance, not 0 |
| Admin → All Subaccounts | Agency column visible, search filters by agency name |
| Master lock ON + agency balance 0 → send SMS | HTTP 402 `agency_master_lock` |  
| Master lock ON + agency balance > 0 → send SMS | Sends normally |
| Admin → Admin Users tab | Table loads real data, no "Backend not reachable" banner |
| Create / reset password / toggle / delete admin user | All work without 401 |
