<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';
$db = get_firestore();

$locationId = 'Is3CjRqD4xzqonUZIOEo';
$docId = 'ghl_' . $locationId;

echo "Checking 'integrations' collection for $docId...\n";
$docRef = $db->collection('integrations')->document($docId);
$snap = $docRef->snapshot();
if ($snap->exists()) {
    echo "Found in 'integrations'!\n";
    print_r($snap->data());
} else {
    echo "NOT found in 'integrations'.\n";
}

echo "\nChecking 'ghl' collection for $docId...\n";
$docRef = $db->collection('ghl')->document($docId);
$snap = $docRef->snapshot();
if ($snap->exists()) {
    echo "Found in 'ghl'!\n";
    print_r($snap->data());
} else {
    echo "NOT found in 'ghl'.\n";
}

echo "\nChecking 'ghl_tokens' collection for $locationId...\n";
$docRef = $db->collection('ghl_tokens')->document($locationId);
$snap = $docRef->snapshot();
if ($snap->exists()) {
    echo "Found in 'ghl_tokens'!\n";
    print_r($snap->data());
} else {
    echo "NOT found in 'ghl_tokens'.\n";
}
