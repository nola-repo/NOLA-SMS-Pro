# Backend Handoff: Platform Activity Feed & Filtering Fixes

**Date**: March 25, 2026

## 1. The Issue
The **Platform Activity** tab (Admin Panel) was experiencing severe filtering bugs:
1. The **Sender IDs** filter was consistently showing `0` (empty).
2. The **Credits Included** vs **Credits Used** (purchases vs deductions) were completely merged together or empty, resulting in negative credit deductions showing up under the "Credits Added" filter.

## 2. Root Cause Analysis
### A. Missing Data (Empty Sender IDs & Credits)
When the Admin panel called `fetch(ADMIN_API + '?action=logs')`, the backend endpoint located in `api/admin_sender_requests.php` was hardcoded to *only* query the `messages` collection. 
Because of this hardcoded constraint, the feed literally had zero access to the `sender_id_requests` or `credit_transactions` arrays. So, the frontend filters for these events were inherently empty.

### B. The Type Misclassification (Merged Credits)
Once we wired the backend to combine the collections, a second issue arose regarding the typing of Credit events.
The `credit_transactions` collection contained records with `amount: -1` and `type: "deduction"`. 
However, the frontend's mapping stringently expected either `credit_usage` or `credit_purchase`. The initial PHP type-casting failed to intercept this properly, defaulting all credit transactions to `credit_purchase`, causing negative deductions to populate under the "Credits Added" grouping pill.

## 3. The Backend Solutions Applied
### `api/admin_sender_requests.php`
The `?action=logs` endpoint was completely overhauled to perform a chronological mega-query across the platform's lifecycle events.

1. **Multi-Collection Fetch**: The backend now independently queries:
   - `messages` (limit 50)
   - `sender_id_requests` (limit 50)
   - `credit_transactions` (limit 50)
2. **Strict Type Tagging**: As the PHP loops over these Firebase documents, it maps the raw DB structures into our specific UI types:
   ```php
   // Safely classifying Negative Deductions vs Positive Purchases
   $origType = $data['type'] ?? '';
   $amt = (float)($data['amount'] ?? 0);
   if ($origType === 'deduction' || $origType === 'usage' || $amt < 0) {
       $data['type'] = 'credit_usage';   // Used
   } else {
       $data['type'] = 'credit_purchase'; // Added
   }
   ```
3. **Array Consolidation & Sorting**: It aggregates all objects into a single `$results` array, executes a `usort` based on the universal `Timestamp` value to put them in exact chronological order, and truncates the return to the top 100 recent global events.

## 4. Frontend Resilience (`AdminLayout.tsx`)
Because of how the platform has evolved, older documents might occasionally have missing `type` strings. We bulletproofed the frontend's `getType` function.
It now strictly forces exact mapping before utilizing a fallback check that evaluates if an undefined/raw numeric string is intrinsically negative via `amount < 0` or `.startsWith('-')`.

```typescript
const getType = (log: any) => {
    if (log.type === 'credit_usage' || log.type === 'deduction') return 'credit_usage';
    if (log.type === 'credit_purchase' || log.type === 'top_up') return 'credit_purchase';
    if (log.type === 'sender_request' || log.requested_id) return 'sender_request';
    if (log.type === 'message') return 'message';
    
    // Fallback for legacy raw data
    const neg = (typeof log.amount === 'number' && log.amount < 0) || 
                (typeof log.amount === 'string' && log.amount.startsWith('-'));
    return log.amount !== undefined ? (neg ? 'credit_usage' : 'credit_purchase') : 'message';
};
```

**Outcome**: The Platform Activity perfectly renders every action natively. Filters reliably isolate all `message`, `sender_request`, `credit_purchase`, and `credit_usage` types.
