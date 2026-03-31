<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();

echo "Checking ghl_tokens collection...\n";
$docs = $db->collection('ghl_tokens')->documents();
foreach ($docs as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        echo "ID: " . $doc->id() . " | Type: " . ($data['userType'] ?? 'N/A') . " | Company: " . ($data['companyId'] ?? 'N/A') . "\n";
    }
}

echo "\nChecking agency_subaccounts collection...\n";
$docs = $db->collection('agency_subaccounts')->documents();
foreach ($docs as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        echo "ID: " . $doc->id() . " | Agency: " . ($data['agency_id'] ?? 'N/A') . " | Name: " . ($data['name'] ?? 'N/A') . "\n";
    }
}
