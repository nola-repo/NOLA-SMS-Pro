# Backend Handoff: Expose Subaccount Credentials in Account Profile

**To:** Backend Team  
**Topic:** Expose `name`, `email`, and `phone` fields in `/api/account.php` for subaccount context  
**Files Changed:** `api/account.php`

---

## Overview

When the NOLA app runs inside a GoHighLevel iframe (or is accessed via the Agency view), the frontend fetches the specific settings for that subaccount using the `X-GHL-Location-ID` header. 

Currently, `api/account.php` only returns generic location details (like `location_name`, `credit_balance`, etc.), causing the Personal Details section (Email, Phone) in the User Settings page to display as "N/A" because the frontend lacks the specific subaccount owner's credentials.

**Goal**: Update `api/account.php` to query the `users` collection for the user linked to the requested `location_id` and include their `name`, `email`, and `phone` in the JSON response.

---

## Required Changes

### Update `api/account.php`

1. After retrieving the `location_name` from `ghl_tokens` or `integrations`, query the `users` collection to find the user document associated with the `location_id`. You can query where `active_location_id == $locId`.
2. Extract `name`, `email`, and `phone` from that user document. If no document is found, or fields are missing, gracefully default to `null` or `''`.
3. Append these fields to the `data` array in the JSON response.

**Example Implementation:**

```php
    // ... existing location resolution logic ...
    $intData = $intSnap->exists() ? $intSnap->data() : [];

    // --- NEW LOGIC: Fetch subaccount user credentials ---
    $userEmail = null;
    $userPhone = null;
    $userName  = null;

    try {
        $userQuery = $db->collection('users')->where('active_location_id', '=', (string)$locId)->limit(1)->documents();
        foreach ($userQuery as $doc) {
            if ($doc->exists()) {
                $userData = $doc->data();
                $userName = $userData['name'] ?? null;
                $userEmail = $userData['email'] ?? null;
                $userPhone = $userData['phone'] ?? null;
                
                // If 'name' is missing, fallback to firstName/lastName
                if (!$userName && (isset($userData['firstName']) || isset($userData['lastName']))) {
                    $userName = trim(($userData['firstName'] ?? '') . ' ' . ($userData['lastName'] ?? ''));
                }
                break;
            }
        }
    } catch (\Throwable $e) {
        // Silently handle if query fails or index is missing
        error_log("[api/account.php] Failed to fetch user credentials: " . $e->getMessage());
    }
    // ----------------------------------------------------

    // 4. Response format
    echo json_encode([
        'status' => 'success',
        'data' => [
            'location_id' => $locId,
            'location_name' => $locationName,
            'email' => $userEmail,            // NEW
            'phone' => $userPhone,            // NEW
            'name' => $userName,              // NEW
            'approved_sender_id' => $intData['approved_sender_id'] ?? null,
            'free_usage_count' => $intData['free_usage_count'] ?? 0,
            'free_credits_total' => $intData['free_credits_total'] ?? 10,
            'credit_balance' => (int)($intData['credit_balance'] ?? 0),
            'currency' => $intData['currency'] ?? 'PHP'
        ]
    ]);
```

## Validation

- Hit `/api/account.php` passing a valid `X-GHL-Location-ID` header.
- Ensure the response `data` object now contains `email`, `phone`, and `name`.
- Check the frontend `Settings.tsx` page to verify that the "N/A" placeholders in the Personal Details section are now correctly populated with the subaccount owner's information.
