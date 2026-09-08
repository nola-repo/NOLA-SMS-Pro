<?php
require_once __DIR__ . '/../api/webhook/config.php';
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$shortopts = "";
$longopts  = [
    "dry-run",
    "apply",
];
$options = getopt($shortopts, $longopts);
$dryRun = !isset($options['apply']);

echo "=== API Key Migration Script ===\n";
if ($dryRun) {
    echo "[!] DRY RUN MODE: No changes will be saved to Firestore.\n";
    echo "Run with --apply to execute the migration.\n\n";
}

$globalKey = getenv('SEMAPHORE_GLOBAL_API_KEY') ?: '';
if ($globalKey === '') {
    echo "ERROR: SEMAPHORE_GLOBAL_API_KEY environment variable is not set.\n";
    echo "Please set this before running the migration.\n";
    exit(1);
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
        
        // Explicitly exclude jnkrental
        if (strcasecmp($approvedSenderId, 'jnkrental') === 0) {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Explicitly excluded\n";
            continue;
        }

        // Exclude unisms accounts so we don't accidentally switch them to Semaphore
        $providerPref = $data['provider_preference'] ?? '';
        $approvedProvider = $data['approved_provider'] ?? '';
        if (strpos($providerPref, 'unisms') !== false || $approvedProvider === 'unisms') {
            $skippedCount++;
            echo "[SKIPPED] {$locationId} (Sender: {$approvedSenderId}) - Uses Unisms\n";
            continue;
        }
        
        $processedCount++;
        echo "[UPDATE] {$locationId} (Sender: {$approvedSenderId})\n";
        
        if (!$dryRun) {
            $updateData = [
                'nola_pro_api_key' => $globalKey,
                'semaphore_api_key' => $globalKey,
                'provider_preference' => 'semaphore_custom'
            ];
            $db->collection('integrations')->document($locationId)->set($updateData, ['merge' => true]);
        }
    }
    
    echo "\n=== Migration Summary ===\n";
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
