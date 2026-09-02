# Backend Handoff: Wallet Balance Migration

**Scope:** Migrate subaccount credit balances and agency wallet balances into the central `users` and `agency_users` collections.
**Goal:** Establish a single source of truth for all user data, subscription state, and credit balances.

---

## 1. Why We Are Migrating

Currently, billing state is fragmented:
- Subaccount balances live in `integrations/{location_id}` (which should strictly be for OAuth config).
- Agency balances live in `agency_wallet/{company_id}`.
- Profiles and Subscriptions live in `users` and `agency_users`.

By migrating balances to `users` and `agency_users`, we:
1. Ensure that one read to the user profile retrieves everything (profile + subscription + balance).
2. Align with the strict 1:1 mapping of `email` -> `location_id` / `company_id`.

---

## 2. Firestore Schema Changes

### `users/{user_id}`
Add the `credit_balance` field to the main user document representing the subaccount.
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "active_location_id": "ghl_abc123",
  "role": "user",
  "credit_balance": 1500  // <--- MIGRATED FROM integrations
}
```

### `agency_users/{agency_user_id}`
Add the `balance` field to the agency document.
```json
{
  "company_name": "NOLA Web Solutions",
  "company_id": "xyz987",
  "role": "agency",
  "subscription_plan": "agency",
  "balance": 50000        // <--- MIGRATED FROM agency_wallet
}
```

---

## 3. Core Service Update: `api/services/CreditManager.php`

Because `users` and `agency_users` use auto-generated Firestore document IDs (e.g., `5fX...`) rather than deterministic IDs (like `ghl_abc123`), the `CreditManager` must resolve the DocumentReference via a query *before* executing the transaction.

### Update `get_account_ref($account_id)`
```php
private function get_account_ref($account_id)
{
    if ($account_id === 'default' || empty($account_id)) {
        return $this->db->collection('users')->document('default'); // Or handle default gracefully
    }

    // Query the users collection where active_location_id matches
    $usersRef = $this->db->collection('users');
    $query = $usersRef->where('active_location_id', '=', $account_id)->limit(1)->documents();
    
    foreach ($query as $doc) {
        if ($doc->exists()) {
            return $doc->reference();
        }
    }
    
    throw new \Exception("User document not found for location_id: " . $account_id);
}
```

### Update `get_agency_ref($agency_id)`
```php
private function get_agency_ref($agency_id)
{
    $agencyUsersRef = $this->db->collection('agency_users');
    $query = $agencyUsersRef->where('company_id', '=', $agency_id)->limit(1)->documents();
    
    foreach ($query as $doc) {
        if ($doc->exists()) {
            return $doc->reference();
        }
    }
    
    throw new \Exception("Agency User document not found for company_id: " . $agency_id);
}
```

> **Note on Transactions:** Firestore requires passing a `DocumentReference` into the transaction closure. Because you run the query *outside* the `runTransaction` closure, there is a minuscule risk of the document ID changing between the query and the transaction. Since user IDs do not change, this pattern is completely safe.

---

## 4. One-Time Migration Script

Create a script `tmp/migrate_wallets.php` and run it via CLI or browser.

```php
<?php
require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

echo "Starting Subaccount Wallet Migration...\n";
$integrations = $db->collection('integrations')->documents();
foreach ($integrations as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    if (!isset($data['credit_balance'])) continue;
    
    $locationId = str_replace('ghl_', '', $doc->id());
    
    // Find matching user
    $userQuery = $db->collection('users')->where('active_location_id', '=', $locationId)->limit(1)->documents();
    foreach ($userQuery as $userDoc) {
        if ($userDoc->exists()) {
            $userDoc->reference()->set([
                'credit_balance' => $data['credit_balance']
            ], ['merge' => true]);
            echo "Migrated balance for location {$locationId}\n";
        }
    }
}

echo "Starting Agency Wallet Migration...\n";
$agencyWallets = $db->collection('agency_wallet')->documents();
foreach ($agencyWallets as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    if (!isset($data['balance'])) continue;
    
    $companyId = $doc->id();
    
    // Find matching agency_user
    $agencyQuery = $db->collection('agency_users')->where('company_id', '=', $companyId)->limit(1)->documents();
    foreach ($agencyQuery as $agencyDoc) {
        if ($agencyDoc->exists()) {
            $agencyDoc->reference()->set([
                'balance' => $data['balance']
            ], ['merge' => true]);
            echo "Migrated balance for agency {$companyId}\n";
        }
    }
}
echo "Migration Complete.\n";
```

---

## 5. API Adjustments

Review these files to ensure they don't have hardcoded references to `integrations` or `agency_wallet` for balance logic:
- `api/billing/subaccount_wallet.php`
- `api/billing/agency_wallet.php`
- `api/credits.php`

**Action:** Update these files to query `users` and `agency_users` respectively, or ideally, have them call `CreditManager->get_balance()` / `CreditManager->get_agency_balance()` to ensure all balance reads flow through the same centralized logic.

---

## 6. Frontend Hook Impact & Optimizations

### Zero-Downtime Compatibility
Because the backend API endpoints (`api/credits.php`, `api/billing/subaccount_wallet.php`, `api/billing/agency_wallet.php`) will abstract this database change and return the exact same JSON response shapes, **the existing frontend hooks will continue to work perfectly without any immediate changes.**

### Optimization Opportunity: `useUserProfile.ts`
Since the wallet balance is now baked directly into the `users` and `agency_users` documents, the frontend no longer needs to make a secondary HTTP request to fetch the balance on load. 

When you are ready, you can update the `useUserProfile.ts` hook to map the new field:

1. **Update the Interface:**
```typescript
export interface UserProfile {
  // ... existing fields
  credit_balance?: number;
}
```

2. **Update `normalizeProfile`:**
```typescript
function normalizeProfile(raw: Record<string, unknown>): UserProfile {
  // ... existing mappings
  return {
    // ...
    credit_balance: typeof raw.credit_balance === 'number' ? raw.credit_balance : undefined,
  };
}
```

Once this is done, any component can instantly read the balance synchronously from `useUserProfileContext()`, eliminating loading spinners on the billing and dashboard pages!
