<?php
require_once __DIR__ . '/../cors.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlSyncService.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data)
    exit;

$senderNumber = $data['sender'] ?? '';
$message = $data['message'] ?? '';
$message_id = $data['message_id'] ?? uniqid();

$db = get_firestore();

// ── Multi-Tenancy: Identify locationId by finding the last sub-account that messaged this contact ────
$locId = null;
// Search for the most recent conversation where this number is a member
$convQuery = $db->collection('conversations')
    ->where('members', 'array-contains', $senderNumber)
    ->orderBy('last_message_at', 'DESC')
    ->documents();

$matchingConvs = [];
foreach ($convQuery as $doc) {
    if ($doc->exists()) {
        $convData = $doc->data();
        $matchingConvs[] = [
            'locId' => $convData['location_id'] ?? null,
            'convId' => $doc->id()
        ];
    }
}

if (empty($matchingConvs)) {
    error_log("[receive_sms] FAILED: No recent conversation or GHL association found for {$senderNumber}. Dropping inbound message to prevent cross-account bleeding.");
    exit(json_encode(["status" => "ignored", "reason" => "unmapped_sender"]));
}

$processed = [];
$now = new \Google\Cloud\Core\Timestamp(new \DateTime());

foreach ($matchingConvs as $matching) {
    $locId = $matching['locId'];
    $convId = $matching['convId'];

    if (!$locId || !$convId)
        continue;

    $saveData = [
        'conversation_id' => $convId,
        'location_id' => $locId,
        'message_id' => $message_id . '_' . $locId, // Unique per location to avoid collisions
        'from' => $senderNumber,
        'message' => $message,
        'direction' => 'inbound',
        'status' => 'Received',
        'date_received' => $now,
    ];

    // 1. Store in messages (unified thread)
    $db->collection('messages')->document($saveData['message_id'])->set($saveData, ['merge' => true]);

    // 2. Update Sidebar (conversations)
    $db->collection('conversations')->document($convId)->set([
        'id' => $convId,
        'location_id' => $locId,
        'last_message' => $message,
        'last_message_at' => $now,
        'updated_at' => $now,
        'type' => 'direct',
        'members' => [$senderNumber]
    ], ['merge' => true]);

    // 3. Sync to GHL (Best-Effort)
    try {
        $ghlSync = new \Nola\Services\GhlSyncService($db, $locId);
        $ghlSync->syncInboundMessage($senderNumber, $message);
    } catch (\Throwable $e) {
        error_log("[receive_sms] GHL Sync failed: " . $e->getMessage());
    }

    $processed[] = $locId;
}

echo json_encode(["status" => "received", "locations_processed" => $processed]);