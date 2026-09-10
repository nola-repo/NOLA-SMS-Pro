<?php
/**
 * Fix sender name mismatches in Firestore:
 * - ZAPPIFYio  → Zappify   (location: U7ncNgNVMsmTs8VLfODh)
 * - LEADSAVVY  → LeadSavvy (location: n3153ZygDYl4FJpEjb5P)
 * Also updates master_senders list.
 * 
 * Run with --apply to execute. Default is dry-run.
 */
require_once __DIR__ . '/../api/webhook/firestore_client.php';

$dryRun = !in_array('--apply', $argv ?? []);
if ($dryRun) {
    echo "[DRY RUN] Pass --apply to execute changes.\n\n";
}

$db = get_firestore();
if (!$db) { echo "ERROR: Firestore failed\n"; exit(1); }

// Sender corrections: [location_id => [old_name, new_name]]
$corrections = [
    'U7ncNgNVMsmTs8VLfODh' => ['old' => 'ZAPPIFYio', 'new' => 'Zappify',   'label' => 'Zappify'],
    'n3153ZygDYl4FJpEjb5P' => ['old' => 'LEADSAVVY', 'new' => 'LeadSavvy', 'label' => 'LeadSavvy'],
];

echo "=== Fixing Integration Docs ===\n";
foreach ($corrections as $locId => $fix) {
    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locId);
    $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
    if (!$intSnap->exists()) {
        echo "  [{$locId}] integration doc NOT FOUND — skipping\n";
        continue;
    }
    $current = $intSnap->data()['approved_sender_id'] ?? '(not set)';
    echo "  [{$locId}] approved_sender_id: '$current' → '{$fix['new']}'\n";
    if (!$dryRun) {
        $db->collection('integrations')->document($intDocId)->set(
            ['approved_sender_id' => $fix['new'], 'updated_at' => new Google\Cloud\Core\Timestamp(new DateTime())],
            ['merge' => true]
        );
        echo "    ✅ Updated.\n";
    }
}

echo "\n=== Fixing sender_id_requests ===\n";
$reqDocs = $db->collection('sender_id_requests')->documents();
foreach ($reqDocs as $req) {
    if (!$req->exists()) continue;
    $d = $req->data();
    $locId = $d['location_id'] ?? '';
    if (!isset($corrections[$locId])) continue;
    $fix = $corrections[$locId];
    $current = $d['requested_id'] ?? $d['sender_id'] ?? '(not set)';
    echo "  [{$req->id()}] requested_id: '$current' → '{$fix['new']}'\n";
    if (!$dryRun) {
        $db->collection('sender_id_requests')->document($req->id())->set(
            ['requested_id' => $fix['new'], 'updated_at' => new Google\Cloud\Core\Timestamp(new DateTime())],
            ['merge' => true]
        );
        echo "    ✅ Updated.\n";
    }
}

echo "\n=== Fixing master_senders list ===\n";
$masterSnap = $db->collection('admin_config')->document('master_senders')->snapshot();
if ($masterSnap->exists()) {
    $senders = $masterSnap->data()['approved_senders'] ?? [];
    echo "  Current list: " . implode(', ', $senders) . "\n";
    $newSenders = array_map(function($s) use ($corrections) {
        foreach ($corrections as $fix) {
            if (strtolower($s) === strtolower($fix['old'])) {
                return $fix['new'];
            }
        }
        return $s;
    }, $senders);
    // Deduplicate
    $newSenders = array_values(array_unique($newSenders));
    echo "  Updated list: " . implode(', ', $newSenders) . "\n";
    if (!$dryRun) {
        $db->collection('admin_config')->document('master_senders')->set(
            ['approved_senders' => $newSenders, 'updated_at' => new Google\Cloud\Core\Timestamp(new DateTime())],
            ['merge' => true]
        );
        echo "  ✅ master_senders updated.\n";
    }
} else {
    echo "  master_senders doc NOT FOUND.\n";
}

echo "\n" . ($dryRun ? "=== DRY RUN complete. Re-run with --apply ===" : "=== APPLY complete ===") . "\n";
