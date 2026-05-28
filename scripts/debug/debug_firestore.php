<?php
require __DIR__ . '/api/webhook/firestore_client.php';
$db = get_firestore();

echo "Checking sms_logs for Queued/Pending status...\n";

$statuses = ['Queued', 'Pending', 'queued', 'pending'];
foreach ($statuses as $status) {
    $query = $db->collection('sms_logs')->where('status', '==', $status);
    $count = count($query->documents()->rows());
    echo "Status '$status': $count matches\n";
}

$all = $db->collection('sms_logs')->limit(5)->documents();
echo "\nLast 5 messages:\n";
foreach ($all as $doc) {
    $data = $doc->data();
    echo "ID: " . $doc->id() . " | Status: " . ($data['status'] ?? 'N/A') . " | Date: " . ($data['date_created'] ? $data['date_created']->formatAsString() : 'N/A') . "\n";
}
