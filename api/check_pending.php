<?php
require __DIR__ . '/webhook/firestore_client.php';
$db = get_firestore();

$query = $db->collection('sms_logs')->where('status', 'in', ['Queued', 'Pending']);
$docs = $query->documents();
$count = 0;
foreach ($docs as $doc) {
    if ($doc->exists()) {
        $count++;
        echo "Found pending message: " . $doc->id() . " (Status: " . $doc->data()['status'] . ")\n";
    }
}

if ($count === 0) {
    echo "No pending or queued messages found in Firestore.\n";
}
else {
    echo "Total pending/queued: $count\n";
}
