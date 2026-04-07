<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
// Do not require agency ID for this global endpoint
validate_agency_request(false); 

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$webhookSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if ($webhookSecret !== 'f7RkQ2pL9zV3tX8cB1nS4yW6') {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

$active_subaccounts = [];
$query = $db->collection('agency_subaccounts')->where('toggle_enabled', '=', true);
$documents = $query->documents();
foreach ($documents as $document) {
    if ($document->exists()) {
        $data = $document->data();
        $active_subaccounts[] = $data;
    }
}

echo json_encode(['status' => 'success', 'active_subaccounts' => $active_subaccounts]);
