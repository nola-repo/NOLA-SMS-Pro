<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$db = get_firestore();
$locId = 'ugBqfQsPtGijLjrmLdmA';
$docId = 'ghl_' . $locId;

// We set them to empty strings so they safely fall back to the system default in config.php
$db->collection('integrations')->document($docId)->set([
    'nola_pro_api_key' => '',
    'semaphore_api_key' => ''
], ['merge' => true]);

echo "Successfully cleared custom API keys for location: " . $locId . "\n";
echo "This subaccount will now successfully deduct NOLA credits.\n";
