# Consolidated Backend Handoff: Credit & SMS Billing System

This document outlines the standardization requirements for the backend team to ensure the "Free Trial" and "Credit Management" features are fully functional and correctly displayed in the Frontend (User, Agency, and Admin panels).

---

## 1. Credit Transaction Logging — `balance_after` Field

### ❌ Current Problem
The Admin Dashboard "Platform Activity" and "Recent Activity" sections show credit events (Credits Used / Credits Purchased) but **cannot display the remaining balance** because the backend does not return a `balance_after` field in the log rows.

**Screenshot**: We can currently see `"Deducted -1 credits"` but the sub-line `"Balance: X credits"` is blank.

### ✅ Required Fix

For every row returned by `/api/admin_sender_requests.php?action=logs`, add a `balance_after` field:

```json
{
  "id": "txn_001",
  "type": "deduction",
  "amount": -1,
  "location_id": "ghl_J3xCOJ...",
  "timestamp": "2025-04-09T14:07:00Z",
  "description": "SMS to 09938905125",
  "balance_after": 249
}
```

#### How to calculate `balance_after`
- For each ledger row (ordered oldest → newest), track the running credit balance per `location_id`.
- `balance_after = previous_balance + amount`
- For Free Trial events (`amount = 0`), `balance_after` should equal the current `credit_balance` unchanged.

> **Important:** The frontend checks `log.balance_after !== undefined` before rendering. If the field is missing or `null`, the balance line is silently hidden. Just adding the field is enough — no frontend changes needed.

---

## 2. Agency Subaccounts — `credit_balance` Field

### ❌ Current Problem
The Agency panel (`/agency`) shows a "Credits" column and "Total Credits" stat card. These always show **0** because `get_subaccounts.php` does not return `credit_balance`.

### ✅ Required Fix

Add `credit_balance` to every subaccount object in:
`GET /api/agency/get_subaccounts.php`

```json
{
  "location_id": "ugBqfQsPtGijLjrmLdmA",
  "location_name": "J&K Car Rentals",
  "toggle_enabled": true,
  "rate_limit": 5,
  "attempt_count": 2,
  "credit_balance": 249
}
```

Fetch this from the same `integrations` Firestore doc (field: `credit_balance`).

---

## 3. Free Trial Credit Logging Standard

### Rule
When a Free Trial SMS is sent (user has no paid credits):

| Field | Value |
|-------|-------|
| `type` | `deduction` |
| `amount` | `0` |
| `balance_after` | Current paid credit balance (unchanged) |

> **Do NOT use** `type=top_up` with `amount=0`. This causes the admin to see **"Credits Purchased: +0"** which is incorrect.

---

## 4. Admin Panel Account Fields

For `/api/admin_sender_requests.php?action=accounts`, ensure each account returns:

| Field | Description |
|-------|-------------|
| `credit_balance` | Current paid SMS credit balance |
| `free_usage_count` | Number of free trial messages sent |
| `free_credits_total` | Max free trial limit (usually `10`) |
| `approved_sender_id` | Active sender registration |
| `semaphore_api_key` | API key for sender display in Admin |

---

*Status: Pending Backend Implementation*
*Frontend: Fully wired and ready — just needs the data fields*

