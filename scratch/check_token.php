<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();
$doc = $db->collection('ghl_tokens')->document('ugBqfQsPtGijLjrmLdmA')->snapshot();
if ($doc->exists()) {
    $data = $doc->data();
    echo "Exists! Updated at: " . $data['updated_at']->get()->format('Y-m-d H:i:s') . "\n";
    echo "Expires at: " . date('Y-m-d H:i:s', $data['expires_at']) . "\n";
    echo "Client ID: " . $data['client_id'] . "\n";
    echo "Now: " . time() . "\n";
} else {
    echo "Not found.";
}
