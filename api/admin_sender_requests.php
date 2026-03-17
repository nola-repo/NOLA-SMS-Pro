<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// Authentication: Admin-only secret or specialized check
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // In production, we'd probably filter by "pending" first, but let's fetch all
    $requests = $db->collection('sender_id_requests')
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    $requestId = $payload['request_id'] ?? null;
    $status = $payload['status'] ?? null; // 'approved' or 'rejected'
    $apiKey = $payload['api_key'] ?? null;

    if (!$requestId || !$status) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing request_id or status']);
        exit;
    }

    // 1. Update the request status
    $requestRef = $db->collection('sender_id_requests')->document($requestId);
    $reqSnapshot = $requestRef->snapshot();
    
    if (!$reqSnapshot->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Request not found']);
        exit;
    }

    $reqData = $reqSnapshot->data();
    $locId = $reqData['location_id'];

    $requestRef->set(['status' => $status], ['merge' => true]);

    // 2. If approved, update the account mapping
    if ($status === 'approved') {
        $accountRef = $db->collection('accounts')->document($locId);
        $accountRef->set([
            'approved_sender_id' => $reqData['requested_id'],
            'semaphore_api_key' => $apiKey // Manual assignment by boss
        ], ['merge' => true]);
    }

    echo json_encode(['status' => 'success', 'message' => "Request $status and account updated."]);
    exit;
}
