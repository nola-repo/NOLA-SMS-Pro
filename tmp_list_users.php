<?php
require __DIR__ . '/api/webhook/firestore_client.php';

$db = get_firestore();
$users = $db->collection('users')->documents();

echo "--- Users in Firestore ---\n";
foreach ($users as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        $email = $data['email'] ?? 'N/A';
        $locationId = $data['active_location_id'] ?? 'N/A';
        $companyId = $data['company_id'] ?? 'N/A';
        echo "ID: " . $doc->id() . " | Email: $email | Location ID: $locationId | Company ID: $companyId\n";
    }
}
echo "--------------------------\n";
