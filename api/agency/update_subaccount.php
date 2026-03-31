<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
$agency_id = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

$location_id = $payload['location_id'] ?? null;
if (!$location_id) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

try {
    $docRef = $db->collection('agency_subaccounts')->document($location_id);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Sub-account not found']);
        exit;
    }

    $updateData = [];
    if (isset($payload['toggle_enabled'])) {
        $updateData['toggle_enabled'] = (bool)$payload['toggle_enabled'];
    }
    if (isset($payload['rate_limit'])) {
        $updateData['rate_limit'] = (int)$payload['rate_limit'];
    }
    if (isset($payload['reset_counter']) && $payload['reset_counter'] === true) {
        $updateData['attempt_count'] = 0;
    }

    if (!empty($updateData)) {
        $updateData['updated_at'] = new \Google\Cloud\Core\Timestamp(new \DateTime());
        $docRef->set($updateData, ['merge' => true]);
    }

    echo json_encode(['status' => 'success', 'message' => 'Sub-account updated successfully']);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
