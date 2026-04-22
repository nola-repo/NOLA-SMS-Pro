<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();
$locId = 'ugBqfQsPtGijLjrmLdmA';
$docId = 'ghl_' . $locId;
$snap = $db->collection('integrations')->document($docId)->snapshot();
if ($snap->exists()) {
    echo "Doc Found: $docId\n";
    echo "approved_sender_id: " . ($snap->data()['approved_sender_id'] ?? 'NULL') . "\n";
    echo "nola_pro_api_key: " . (isset($snap->data()['nola_pro_api_key']) ? 'SET' : 'MISSING') . "\n";
    echo "semaphore_api_key: " . (isset($snap->data()['semaphore_api_key']) ? 'SET' : 'MISSING') . "\n";
    echo "credit_balance: " . ($snap->data()['credit_balance'] ?? 0) . "\n";
} else {
    echo "Doc NOT Found: $docId\n";
}
