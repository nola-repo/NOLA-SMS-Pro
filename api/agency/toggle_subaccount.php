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

if (!isset($payload['enabled'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing enabled flag']);
    exit;
}

$enabled = filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN);

if (!$subaccount_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing subaccount_id']);
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

$activation_count = $data['toggle_activation_count'] ?? 0;

if ($enabled) {
    if ($activation_count >= 3) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Max activation limit reached. Please upgrade to add more.']);
        exit;
    }
    $activation_count++;
}

$updateData = [
    'toggle_enabled' => $enabled,
    'toggle_activation_count' => $activation_count
];

$docRef->set($updateData, ['merge' => true]);

// ── Mirror toggle_enabled into ghl_tokens (enforcement layer) ──────────────
$tokenRef = $db->collection('ghl_tokens')->document($subaccount_id);
$tokenSnap = $tokenRef->snapshot();
if ($tokenSnap->exists()) {
    $tokenRef->set([
        'toggle_enabled' => $enabled,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable())
    ], ['merge' => true]);
}

echo json_encode([
    'status' => 'success',
    'subaccount_id' => $subaccount_id,
    'toggle_enabled' => $enabled,
    'toggle_activation_count' => $activation_count
]);
