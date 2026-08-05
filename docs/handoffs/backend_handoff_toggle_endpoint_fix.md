# Backend Handoff — Agency Toggle Optimization & Endpoint Fix

**Date**: April 15, 2026
**Priority**: High
**Target Repo**: https://github.com/nola-repo/NOLA-SMS-Pro
**Affected Path**: `frontend` and `api/agency/update_subaccount.php`

---

## 1. Issue Overview
Users reported that "when turning on the toggle, it turns off after a few seconds and comes back."
This happened because the frontend was optimistically updating the UI but calling a missing endpoint (`toggle_subaccount.php`), which failed the proxy request, rolled back the optimistic update, and reverted UI when polling the database.

## 2. Changes Made (Frontend)
We refactored the frontend's API service (`agency/src/services/api.ts`) to point `toggleSubaccount` directly to **`update_subaccount.php`**.
*   **Method**: `POST`
*   **Payload**: Uses `location_id` and `toggle_enabled` explicitly since `update_subaccount.php` knows how to handle the 3-limit max activations correctly and sync state to both collections.
*   **UI Polish**: The `Dashboard.tsx` greeting was also enhanced to extract the real Agency Name from the loaded subaccounts (e.g., "Good morning, Acme Agency"), rather than a hardcoded "Agency."

## 3. Backend Deployment Requirements (Remote)
To ensure the backend properly handles toggling via `update_subaccount.php`, you must deploy the *local* version of `api/agency/update_subaccount.php` to the staging/production servers.

Specifically, the local `update_subaccount.php` contains the logic to write the toggle flag to **`ghl_tokens`** (the source of truth for webhook enforcement) and then mirror it directly to **`agency_subaccounts`** (the frontend UI display source).

```php
    // Enforce 3 max activations for "toggle_enabled"
    if ($toggleEnabled && !($currentData['toggle_enabled'] ?? false)) {
        $activations = (int)($currentData['toggle_activation_count'] ?? 0);
        if ($activations >= 3) {
            http_response_code(403);
            echo json_encode(['error' => 'Activation Limit Reached', 'status' => 'limit_reached']);
            exit;
        }
        $updates['toggle_activation_count'] = $activations + 1;
    }
    
    // Apply updates to ghl_tokens
    $docRef->set($updates, ['merge' => true]);

    // Mirror to agency_subaccounts
    $subaccountRef = $db->collection('agency_subaccounts')->document($locationId);
    ...
```

Make sure that `ghl_provider.php` and `send_sms.php` are checking `ghl_tokens['toggle_enabled']` remotely, as the toggle is now 100% real-time and fully synchronized.
