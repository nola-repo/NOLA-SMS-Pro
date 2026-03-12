<?php
require 'c:/Users/niceo/public_html/api/webhook/firestore_client.php';

$db = get_firestore();

echo "--- Verification Results ---\n\n";

// 1. Check GHL Integration tokens
echo "1. Checking GHL Integration tokens...\n";
$integrations = $db->collection('integrations')->limit(1)->documents();
$tokensExist = false;
foreach ($integrations as $doc) {
    if ($doc->exists()) {
        $d = $doc->data();
        echo "   [SUCCESS] Found GHL integration for location: " . ($d['location_id'] ?? 'unknown') . "\n";
        $tokensExist = true;
    }
}
if (!$tokensExist) echo "   [WARNING] No GHL integration tokens found in Firestore. Please run OAuth callback.\n";

// 2. Verify collection consistency
echo "\n2. Verifying Firestore collection consistency...\n";
$collections = ['messages', 'sms_logs', 'conversations', 'contacts'];
foreach ($collections as $col) {
    $docs = $db->collection($col)->limit(1)->documents();
    $found = false;
    foreach ($docs as $doc) {
        $found = true;
        break;
    }
    echo "   [" . ($found ? "SUCCESS" : "INFO") . "] Collection '$col' results: " . ($found ? "Documents exist" : "Empty") . "\n";
}

// 3. Status sync check (retrieve_status.php logic fallback)
echo "\n3. Checking status update logic consistency...\n";
$pending = $db->collection('sms_logs')->where('status', 'in', ['Queued', 'Pending', 'sent'])->limit(1)->documents();
foreach ($pending as $doc) {
    if ($doc->exists()) {
        $mid = $doc->data()['message_id'] ?? null;
        if ($mid) {
            echo "   [SUCCESS] Found message with trackable status: $mid\n";
        }
    }
}

echo "\n--- Verification Complete ---\n";
