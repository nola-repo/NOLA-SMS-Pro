<?php

require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();
$docs = $db->collection('ghl_tokens')->limit(5)->documents();

echo "Active locations in ghl_tokens:\n";
foreach ($docs as $doc) {
    if ($doc->exists()) {
        $data = $doc->data();
        echo "ID: " . $doc->id() . " | Name: " . ($data['location_name'] ?? 'N/A') . " | State: " . ($data['install_state'] ?? 'N/A') . "\n";
    }
}
