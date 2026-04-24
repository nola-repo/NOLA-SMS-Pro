<?php
require __DIR__ . '/api/webhook/firestore_client.php';

$db = get_firestore();
$docId = 'd52719785eda4c9a930f';

try {
    $docRef = $db->collection('users')->document($docId);
    $snapshot = $docRef->snapshot();

    if ($snapshot->exists()) {
        $data = $snapshot->data();
        echo "Found document: $docId\n\n";
        echo json_encode($data, JSON_PRETTY_PRINT);
    } else {
        echo "Document $docId does not exist in the 'users' collection.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
