<?php
require_once 'c:/Users/niceo/public_html/api/webhook/firestore_client.php';
require_once 'c:/Users/niceo/public_html/api/services/GhlClient.php';
try {
    $db = get_firestore();
    $agencyId = 'nola-repo/nola-sms-pro'; // or something
    $client = new GhlClient($db, $agencyId);
    echo "Success\n";
} catch (Exception $e) {
    echo "Caught: " . $e->getMessage() . "\n";
}
