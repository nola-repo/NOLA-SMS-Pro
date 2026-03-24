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
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

$db = get_firestore();

try {
    // 3. Database Query (Source: integrations collection)
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();

    $data = $intSnap->exists() ? $intSnap->data() : [];

    // The frontend expects this exact shape:
    // {
    //   "status": "success",
    //   "approved_sender_id": "YourBrand",     // null if none
    //   "system_default_sender": "NOLASMSPro"
    // }
    echo json_encode([
        'status' => 'success',
        'approved_sender_id' => $data['approved_sender_id'] ?? null,
        'system_default_sender' => 'NOLASMSPro'
    ]);
    exit;

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error', 'details' => $e->getMessage()]);
}
