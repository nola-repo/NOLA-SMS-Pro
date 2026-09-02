# Backend Handoff: Fixing GHL Subaccount Name Display (`api/account.php`)

## The Issue
The subaccount name was occasionally appearing as `"NOLA SMS Pro"` (or `"Unknown"`) instead of the actual GoHighLevel subaccount name (e.g., `"NOLA CRM Angeles City..."`).

This happened because the background data for a location name can exist in two collections, depending on how and when the account onboarded:
1. `integrations` collection (older accounts / default names)
2. `ghl_tokens` collection (newer accounts using the latest OAuth flow - contains the correct name)

Currently, the backend queries the `integrations` collection **first**. If it finds an old document (which often just contains the default "NOLA SMS Pro" name), it uses that and ignores the `ghl_tokens` collection entirely.

## The Required Fix
Update `api/account.php` to reverse the fallback order. It needs to query the `ghl_tokens` collection **first**, and only fallback to `integrations` if the name is not found.

### Code Changes (`api/account.php`)

Locate the "Database Query" section step 3 and replace the data retrieval logic.

**Old Logic (Incorrect Order):**
```php
// 3. Database Query
$docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
$docRef = $db->collection('integrations')->document($docId);
$snapshot = $docRef->snapshot();

$locationName = 'Unknown';
if ($snapshot->exists()) {
    $data = $snapshot->data();
    $locationName = $data['location_name'] ?? 'Unknown';
}

if ($locationName === 'Unknown' || empty($locationName)) {
    $tokenRef = $db->collection('ghl_tokens')->document((string)$locId);
    $tokenSnap = $tokenRef->snapshot();
    if ($tokenSnap->exists()) {
        $tokenData = $tokenSnap->data();
        $locationName = $tokenData['location_name'] ?? 'Unknown';
    }
}
```

**New Logic (Correct Order):**
```php
// 3. Database Query
$locationName = 'Unknown';

// 1. FIRST check the newer 'ghl_tokens' collection
$tokenRef = $db->collection('ghl_tokens')->document((string)$locId);
$tokenSnap = $tokenRef->snapshot();
if ($tokenSnap->exists()) {
    $tokenData = $tokenSnap->data();
    $locationName = $tokenData['location_name'] ?? 'Unknown';
}

// 2. FALLBACK to 'integrations' collection if still Unknown
if ($locationName === 'Unknown' || empty($locationName)) {
    $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $docRef = $db->collection('integrations')->document($docId);
    $snapshot = $docRef->snapshot();
    if ($snapshot->exists()) {
        $data = $snapshot->data();
        $locationName = $data['location_name'] ?? 'Unknown';
    }
}
```

*Note: Make sure to also check further down in the file for `$data = $snapshot->exists() ? $snapshot->data() : [];` as the `$snapshot` variable might be undefined with the new structure if the fallback isn't hit. Simply remove any lines relying on `$snapshot` data payload further down if they exist, or initialize `$data = [];` earlier.*
