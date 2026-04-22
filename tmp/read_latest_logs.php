<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';
$db = get_firestore();
$logs = $db->collection('sms_logs')->orderBy('createdAt', 'desc')->limit(3)->snapshot();
foreach($logs as $l) {
    echo "ID: " . $l->id() . "\n";
    print_r($l->data());
    echo "-------------------\n";
}
