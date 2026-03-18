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
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Fetch sender ID requests for this location
        $query = $db->collection('sender_requests')
                    ->where('location_id', '==', $locId)
                    ->orderBy('created_at', 'DESC')
                    ->limit(50);
        
        $results = [];
        foreach ($query->documents() as $doc) {
            if ($doc->exists()) {
                $d = $doc->data();
                $results[] = [
                    'id' => $doc->id(),
                    'location_id' => $d['location_id'] ?? $locId,
                    'requested_id' => $d['requested_id'] ?? '',
                    'status' => $d['status'] ?? 'pending',
                    'purpose' => $d['purpose'] ?? '',
                    'sample_message' => $d['sample_message'] ?? '',
                    'created_at' => (isset($d['created_at']) && $d['created_at'] instanceof \Google\Cloud\Core\Timestamp) 
                        ? $d['created_at']->get()->format('Y-m-d H:i:s') 
                        : null
                ];
            }
        }

        echo json_encode($results);
        exit;
    }

    if ($method === 'POST') {
        // Submit new sender ID request
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) $payload = $_POST;

        $requestedId = $payload['requested_id'] ?? null;
        $purpose = $payload['purpose'] ?? '';
        $sampleMessage = $payload['sample_message'] ?? '';

        if (!$requestedId) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing requested_id']);
            exit;
        }

        // Validate requested_id format: length 3-11, alphanumeric only (no spaces)
        if (!preg_match('/^[a-zA-Z0-9]{3,11}$/', $requestedId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Sender Name must be 3-11 alphanumeric characters with no spaces']);
            exit;
        }

        $requestId = 'req_' . bin2hex(random_bytes(8));
        $now = new \Google\Cloud\Core\Timestamp(new \DateTime());

        // Update database payload schema to match requirements
        $db->collection('sender_requests')->document($requestId)->set([
            'location_id' => $locId,
            'requested_id' => $requestedId,
            'purpose' => $purpose,
            'sample_message' => $sampleMessage,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now
        ]);

        echo json_encode(['status' => 'success', 'id' => $requestId]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error', 'details' => $e->getMessage()]);
}
