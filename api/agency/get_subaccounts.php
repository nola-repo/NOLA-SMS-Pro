<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
$agency_id = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

$subaccounts = [];
$query = $db->collection('agency_subaccounts')->where('agency_id', '=', $agency_id);
$documents = $query->documents();
foreach ($documents as $document) {
    if ($document->exists()) {
        $data = $document->data();
        $subaccounts[] = $data;
    }
}

echo json_encode(['status' => 'success', 'subaccounts' => $subaccounts]);
