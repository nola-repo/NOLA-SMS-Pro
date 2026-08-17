<?php
/**
 * seed_master_senders.php — One-time script to initialize the dynamic master senders list.
 * 
 * Run this ONCE after deploying the updated send_sms.php / ghl_provider.php / admin_sender_requests.php
 * to seed the Firestore `admin_config/master_senders` document with:
 *   1. The system defaults (NOLASMSPro, NOLA CRM)
 *   2. All currently-approved sender IDs from integrations
 *
 * Usage: php seed_master_senders.php
 */

require __DIR__ . '/api/webhook/firestore_client.php';

$db = get_firestore();

// 1. Start with system defaults
$approvedSenders = ['NOLASMSPro', 'NOLA CRM'];

// 2. Scan all integrations for existing approved_sender_id values
$integrations = $db->collection('integrations')->documents();
foreach ($integrations as $doc) {
    if (!$doc->exists()) continue;
    $data = $doc->data();
    $senderId = $data['approved_sender_id'] ?? null;
    if ($senderId && !in_array($senderId, $approvedSenders)) {
        $approvedSenders[] = $senderId;
    }
}

// 3. Write to Firestore
$db->collection('admin_config')->document('master_senders')->set([
    'approved_senders' => $approvedSenders,
    'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    'seeded_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
], ['merge' => true]);

echo "✅ Seeded admin_config/master_senders with " . count($approvedSenders) . " senders:\n";
foreach ($approvedSenders as $s) {
    echo "   - {$s}\n";
}
