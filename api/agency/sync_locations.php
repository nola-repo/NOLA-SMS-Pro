<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
$agency_id = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/GhlClient.php';

$db = get_firestore();

try {
    // 1. Find Agency Token
    // We look in ghl_tokens for a document where companyId matches agency_id
    $tokenData = null;
    $query = $db->collection('ghl_tokens')
        ->where('companyId', '==', $agency_id)
        ->limit(1)
        ->documents();

    foreach ($query as $doc) {
        if ($doc->exists()) {
            $tokenData = $doc->data();
            $tokenData['id'] = $doc->id();
            break;
        }
    }

    if (!$tokenData) {
        // Fallback: check if the agency_id itself is the document ID
        $doc = $db->collection('ghl_tokens')->document($agency_id)->snapshot();
        if ($doc->exists()) {
            $tokenData = $doc->data();
            $tokenData['id'] = $doc->id();
        }
    }

    if (!$tokenData) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Agency token not found. Please ensure the app is installed at the Agency level.']);
        exit;
    }

    // 2. Initialize GHL Client
    // We use the ID of the token document (which should have the tokens)
    $ghlClient = new GhlClient($db, $tokenData['id']);

    // 3. Fetch Locations from GHL (with Pagination)
    $allLocations = [];
    $limit = 100;
    $skip = 0;
    $hasMore = true;

    while ($hasMore) {
        $path = "/locations/search?companyId=" . urlencode($agency_id) . "&limit=$limit&skip=$skip";
        $response = $ghlClient->request('GET', $path);

        if ($response['status'] !== 200) {
            http_response_code($response['status']);
            echo json_encode(['status' => 'error', 'message' => 'Failed to fetch locations from GHL', 'details' => json_decode($response['body'], true)]);
            exit;
        }

        $ghlData = json_decode($response['body'], true);
        $batch = $ghlData['locations'] ?? [];
        $allLocations = array_merge($allLocations, $batch);

        if (count($batch) < $limit) {
            $hasMore = false;
        } else {
            $skip += $limit;
        }
    }

    $syncedCount = 0;
    $now = new \Google\Cloud\Core\Timestamp(new \DateTime());

    // 4. Upsert into agency_subaccounts
    foreach ($allLocations as $loc) {
        $locId = $loc['id'] ?? null;
        if (!$locId) continue;

        $docRef = $db->collection('agency_subaccounts')->document($locId);
        $snapshot = $docRef->snapshot();

        $updateData = [
            'agency_id' => $agency_id,
            'name'      => $loc['name'] ?? 'Unnamed Location',
            'email'     => $loc['email'] ?? '',
            'phone'     => $loc['phone'] ?? '',
            'address'   => $loc['address'] ?? '',
            'city'      => $loc['city'] ?? '',
            'country'   => $loc['country'] ?? '',
            'updated_at'=> $now
        ];

        if (!$snapshot->exists()) {
            // New sub-account: set defaults
            $updateData['toggle_enabled'] = false;
            $updateData['rate_limit']     = 100;
            $updateData['attempt_count']  = 0;
            $updateData['created_at']     = $now;
        }

        $docRef->set($updateData, ['merge' => true]);
        $syncedCount++;
    }

    echo json_encode([
        'status' => 'success', 
        'message' => "Synced $syncedCount locations for agency $agency_id",
        'count' => $syncedCount
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
