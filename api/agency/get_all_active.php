<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
// This global endpoint does not require a specific agency ID, but still requires valid auth
validate_agency_request(false);

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
