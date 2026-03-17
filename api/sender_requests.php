<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// Authentication
validate_api_request();

// Get Location ID
$locId = get_ghl_location_id();
if (!$locId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    if (!isset($payload['requested_id'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing requested_id']);
        exit;
    }

    $requestData = [
        'location_id' => $locId,
        'requested_id' => $payload['requested_id'],
        'purpose' => $payload['purpose'] ?? '',
        'sample' => $payload['sample'] ?? '',
        'status' => 'pending',
        'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
    ];

    $db->collection('sender_id_requests')->add($requestData);

    echo json_encode(['status' => 'success', 'message' => 'Request submitted']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $requests = $db->collection('sender_id_requests')
        ->where('location_id', '=', $locId)
        ->orderBy('created_at', 'DESC')
        ->documents();

    $results = [];
    foreach ($requests as $request) {
        $data = $request->data();
        if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
            $data['created_at'] = $data['created_at']->get()->format('Y-m-d H:i:s');
        }
        $data['id'] = $request->id();
        $results[] = $data;
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
