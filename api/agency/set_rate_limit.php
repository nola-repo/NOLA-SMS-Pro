<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
$agency_id = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
$subaccount_id = $payload['subaccount_id'] ?? null;
$rate_limit = isset($payload['rate_limit']) ? (int)$payload['rate_limit'] : null;

if (!$subaccount_id || $rate_limit === null) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing subaccount_id or rate_limit']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

$docRef = $db->collection('agency_subaccounts')->document($subaccount_id);
$snap = $docRef->snapshot();

if (!$snap->exists()) {
    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'Subaccount not found']);
    exit;
}

$data = $snap->data();
if ($data['agency_id'] !== $agency_id) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Forbidden: Subaccount does not belong to your agency']);
    exit;
}

$docRef->set(['rate_limit' => $rate_limit], ['merge' => true]);

echo json_encode([
    'status' => 'success',
    'subaccount_id' => $subaccount_id,
    'rate_limit' => $rate_limit
]);
