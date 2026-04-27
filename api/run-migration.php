<?php
// run-migration.php
require __DIR__ . '/webhook/firestore_client.php';

// Simple security check (in case someone guesses the URL during the 2 minutes it's live)
if (($_GET['secret'] ?? '') !== 'nolamigration2026') {
    die("Unauthorized");
}

$db = get_firestore();

$docs = $db->collection('integrations')->documents();
$count = 0;

foreach ($docs as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();

    // Only backfill if client_id is missing
    if (!empty($data['client_id'])) continue;

    $doc->reference()->set([
        'client_id' => '6999da2b8f278296d95f7274-mm9wv85e',  // User/Sub-account App
        'app_type'  => 'location',
    ], ['merge' => true]);

    echo "Backfilled: " . $doc->id() . "<br>\n";
    $count++;
}

echo "Done. Backfilled {$count} documents.<br>\n";
