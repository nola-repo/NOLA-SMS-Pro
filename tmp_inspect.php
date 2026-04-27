<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();
$doc = $db->collection('ghl_tokens')->document('ugBqfQsPtGijLjrmLdmA')->snapshot();
if ($doc->exists()) {
    print_r($doc->data());
} else {
    echo "Document not found.";
}
