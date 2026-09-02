# Backend Handoff: Missing Location Names in Sender ID Requests List

## The Issue
The backend team correctly implemented the auto-fetching of missing location names into `ghl_tokens` and `integrations`. This was successful!

However, on the Admin Dashboard, the **"Sender ID Requests"** tab still shows "Unknown Location" for all the requests. 
This is because the `/api/admin_sender_requests.php` endpoint (when called *without* `?action=accounts`) only returns the raw documents from the `sender_id_requests` collection. That collection only stores `location_id`, not `location_name`.

## The Solution
We need to update the main GET block in `admin_sender_requests.php` to perform a quick lookup in the `ghl_tokens` collection to inject the real `location_name` into the response array before returning it to the frontend.

**Update `api/admin_sender_requests.php` (around line 14):**
```php
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_GET['action']) || $_GET['action'] !== 'accounts')) {
    // Fetch all pending/approved requests
    $requests = $db->collection('sender_id_requests')
        ->orderBy('created_at', 'DESC')
        ->documents();

    // Prepare a temporary cache to avoid duplicate Firestore lookups
    $locationNamesMap = [];

    $results = [];
    foreach ($requests as $request) {
        $data = $request->data();
        if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
            $data['created_at'] = $data['created_at']->get()->format('Y-m-d H:i:s');
        }
        $data['id'] = $request->id();
        
        // --- NEW LOGIC: Inject location_name ---
        $locId = $data['location_id'] ?? '';
        $locationName = 'Unknown Location';
        
        if ($locId) {
            // Check cache first to save reads
            if (isset($locationNamesMap[$locId])) {
                $locationName = $locationNamesMap[$locId];
            } else {
                // Fetch from ghl_tokens
                $locSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
                if ($locSnap->exists()) {
                    $locationName = $locSnap->data()['location_name'] ?? 'Unknown Location';
                }
                $locationNamesMap[$locId] = $locationName; // Store in cache
            }
        }
        $data['location_name'] = $locationName;
        // ----------------------------------------

        $results[] = $data;
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
```

### Why This Works
By creating a small `$locationNamesMap` lookup cache during the loop, we only read from the `ghl_tokens` collection once per unique location ID. The frontend expects `location_name` to be present in the `data` array for this endpoint to render it correctly!
