# Backend Handoff — Settings Stats Always Show 0

**File fixed:** `api/credits.php`  
**Date:** 2026-04-29

---

## Problem

In the Settings → Credits tab, the three stat cards:
- **Sent Today** → always `0`
- **Credits Used Today** → always `0`
- **This Month** → always `0`

…even after successfully sending SMS messages.

---

## Root Cause

The stats query (bottom of `credits.php` GET handler) filtered transactions by:

```php
if (($tx['type'] ?? '') === 'deduction') { ... }
```

But the **current** SMS deduction path (`CreditManager::deduct_subaccount_only()`) writes transactions with:

```php
'type' => 'sms_usage'   // NOT 'deduction'
```

The old `deduct_credits()` path wrote `'type' => 'deduction'`, but all active SMS sends now go through `deduct_subaccount_only()`. So **zero transactions ever matched the filter**, keeping stats at 0.

---

## Fix Applied (already committed)

**`api/credits.php` — Stats block (~line 212)**

Changed the transaction type filter from:
```php
if (($tx['type'] ?? '') === 'deduction') {
```

To:
```php
$isSmsDeduction = ($txType === 'deduction' || $txType === 'sms_usage')
    && ($tx['wallet_scope'] ?? '') !== 'agency'; // exclude agency mirror rows
```

Also fixed `sent_today` to use `max(1, $amt + $freeApplied)` so free-trial sends (where `amount = 0`) are still counted as 1 message sent.

---

## Required Backend Action: Firestore Composite Index

The stats query performs a **compound filter** on the `credit_transactions` collection:

```
WHERE account_id = "ghl_XXXXX"
  AND created_at >= <start of month>
```

Firestore **requires a composite index** for this. Without it, the query silently fails and returns the fallback zeros.

### Index to Create

Go to **Firebase Console → Firestore → Indexes → Composite → Add Index**:

| Field | Order |
|-------|-------|
| `account_id` | Ascending |
| `created_at` | Ascending |

- **Collection:** `credit_transactions`
- **Query scope:** Collection

Or deploy via `firestore.indexes.json`:

```json
{
  "indexes": [
    {
      "collectionGroup": "credit_transactions",
      "queryScope": "COLLECTION",
      "fields": [
        { "fieldPath": "account_id", "order": "ASCENDING" },
        { "fieldPath": "created_at", "order": "ASCENDING" }
      ]
    }
  ]
}
```

Then run:
```bash
firebase deploy --only firestore:indexes
```

> **Note:** Without this index, the stats will silently return zeros even after the code fix. Check the `error` field in the API response — if it says "Stats query failed — likely missing Firestore composite index", the index is missing.

---

## How to Verify After Deploy

1. Send at least one SMS from a subaccount.
2. Open Settings → Credits tab.
3. All three cards should now show real values:
   - **Sent Today** ≥ 1
   - **Credits Used Today** ≥ 1
   - **This Month** ≥ 1

Alternatively, call the endpoint directly:
```
GET https://smspro-api.nolacrm.io/api/credits?location_id=YOUR_LOCATION_ID
```
Check the `stats` object in the JSON response.

---

## No Frontend Changes Needed

The frontend already reads `data.stats.sent_today`, `credits_used_today`, and `credits_used_month` correctly. This was purely a backend bug.
