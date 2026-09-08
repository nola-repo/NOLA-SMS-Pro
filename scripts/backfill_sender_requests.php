<?php
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$shortopts = "";
$longopts  = [
    "dry-run",
    "apply",
];
$options = getopt($shortopts, $longopts);
$dryRun = !isset($options['apply']);

echo "=== Sender Requests Backfill Script ===\n";
if ($dryRun) {
    echo "[!] DRY RUN MODE: No changes will be saved to Firestore.\n";
    echo "Run with --apply to execute the backfill.\n\n";
}

try {
    $db = get_firestore();
    $integrationsSnap = $db->collection('integrations')->documents();
    
    $processedCount = 0;
    $skippedCount = 0;
    
    foreach ($integrationsSnap as $doc) {
        if (!$doc->exists()) continue;
        
        $data = $doc->data();
        $approvedSenderId = trim((string)($data['approved_sender_id'] ?? ''));
        $locationId = $doc->id();
        
        // Skip if no approved sender
        if ($approvedSenderId === '') {
            continue;
        }

        // Exclude unisms accounts
        $providerPref = $data['provider_preference'] ?? '';
        $approvedProvider = $data['approved_provider'] ?? '';
        if (strpos($providerPref, 'unisms') !== false || $approvedProvider === 'unisms') {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Uses Unisms\n";
            continue;
        }
        
        // Check if a sender_id_requests doc already exists for this sender ID
        // The ID format is typically locationId_senderId or similar, but let's query the collection
        $requestsQuery = $db->collection('sender_id_requests')
            ->where('location_id', '=', $locationId)
            ->where('sender_id', '=', $approvedSenderId)
            ->limit(1)
            ->documents();
            
        $exists = false;
        foreach ($requestsQuery as $reqDoc) {
            if ($reqDoc->exists()) {
                $exists = true;
                break;
            }
        }
        
        if ($exists) {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Request doc already exists\n";
            continue;
        }
        
        $processedCount++;
        echo "[BACKFILL] {$locationId} (Sender: {$approvedSenderId})\n";
        
        if (!$dryRun) {
            $requestDocId = $locationId . '_' . md5($approvedSenderId);
            $backfillData = [
                'location_id' => $locationId,
                'sender_id' => $approvedSenderId,
                'status' => 'approved',
                'created_at' => \Google\Cloud\Firestore\FieldValue::serverTimestamp(),
                'updated_at' => \Google\Cloud\Firestore\FieldValue::serverTimestamp(),
                'notes' => 'Backfilled from existing integrations record during API key migration',
                'provider' => 'semaphore'
            ];
            $db->collection('sender_id_requests')->document($requestDocId)->set($backfillData);
        }
    }
    
    echo "\n=== Backfill Summary ===\n";
    echo "Processed: {$processedCount}\n";
    echo "Skipped: {$skippedCount}\n";
    if ($dryRun) {
        echo "Status: DRY RUN COMPLETE\n";
    } else {
        echo "Status: APPLY COMPLETE\n";
    }
    
} catch (\Throwable $e) {
    echo "FATAL ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
