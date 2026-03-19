<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();

if (!$locId) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing location_id'
    ]);
    exit;
}

try {
    $db = get_firestore();
    
    // 3. Database Query
    $locationName = 'Unknown';

    // 1. FIRST check the newer 'ghl_tokens' collection
    $tokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
    if ($tokenSnap->exists()) {
        $tokenData = $tokenSnap->data();
        $locationName = $tokenData['location_name'] ?? 'Unknown';
    }

    // 2. FALLBACK to 'integrations' collection if still Unknown
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();

    if ($locationName === 'Unknown' || empty($locationName)) {
        if ($intSnap->exists()) {
            $intData = $intSnap->data();
            $locationName = $intData['location_name'] ?? 'Unknown';
        }
    }

    $intData = $intSnap->exists() ? $intSnap->data() : [];

    // 4. Response format
    echo json_encode([
        'status' => 'success',
        'data' => [
            'location_id' => $locId,
            'location_name' => $locationName,
            'approved_sender_id' => $intData['approved_sender_id'] ?? null,
            'free_usage_count' => $intData['free_usage_count'] ?? 0,
            'free_credits_total' => $intData['free_credits_total'] ?? 10,
            'credit_balance' => (int)($intData['credit_balance'] ?? 0),
            'currency' => $intData['currency'] ?? 'PHP'
        ]
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Internal Server Error',
        'details' => $e->getMessage()
    ]);
}
