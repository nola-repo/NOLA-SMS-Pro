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
    // The requirement says: location_id to query the integrations collection (document id: ghl_{location_id})
    $docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $docRef = $db->collection('integrations')->document($docId);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        http_response_code(404);
        echo json_encode([
            'status' => 'error',
            'message' => 'Account profile not found'
        ]);
        exit;
    }

    $data = $snapshot->data();
    
    // Fetch account settings for sender and usage
    $accountRef = $db->collection('accounts')->document($locId);
    $accountSnap = $accountRef->snapshot();
    $accountData = $accountSnap->exists() ? $accountSnap->data() : [];

    // 4. Response format
    // Must return location_id and location_name. Do NOT return OAuth tokens.
    echo json_encode([
        'status' => 'success',
        'data' => [
            'location_id' => $locId,
            'location_name' => $data['location_name'] ?? 'Unknown',
            'approved_sender_id' => $accountData['approved_sender_id'] ?? null,
            'free_usage_count' => $accountData['free_usage_count'] ?? 0
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
