<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// Authentication: Admin-only secret or specialized check
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (!isset($_GET['action']) || $_GET['action'] !== 'accounts')) {
    // In production, we'd probably filter by "pending" first, but let's fetch all
    $requests = $db->collection('sender_requests')
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
    $note = $payload['note'] ?? null;

    if (!$requestId || !in_array($status, ['approved', 'rejected'])) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Missing or invalid request_id or status']);
        exit;
    }

    // 1. Update the request status
    $requestRef = $db->collection('sender_requests')->document($requestId);
    $reqSnapshot = $requestRef->snapshot();
    
    if (!$reqSnapshot->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Request not found']);
        exit;
    }

    $reqData = $reqSnapshot->data();
    $locId = $reqData['location_id'];

    $updateData = ['status' => $status];
    if ($status === 'rejected' && $note) {
        $updateData['admin_notes'] = $note;
    }
    $updateData['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTime());

    $requestRef->set($updateData, ['merge' => true]);

    // 2. If approved, update the account mapping
    if ($status === 'approved') {
        $accountRef = $db->collection('accounts')->document($locId);
        $accountRef->set([
            'approved_sender_id' => $reqData['requested_id'],
            'nola_pro_api_key' => $apiKey // Manual assignment by boss
        ], ['merge' => true]);
    }

    echo json_encode(['status' => 'success', 'message' => "Request $status and account updated."]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'accounts') {
    // High-level overview of GHL accounts
    $accounts = $db->collection('accounts')->documents();
    $results = [];
    foreach ($accounts as $acc) {
        $results[] = [
            'id' => $acc->id(),
            'data' => $acc->data()
        ];
    }
    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
