<?php
require_once __DIR__ . '/../cors.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlSyncService.php';

require_once __DIR__ . '/../auth_helpers.php';
// Requires ?secret= to be appended to the webhook URL in Semaphore
validate_api_request();

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

// Route replies to one canonical scoped conversation only. If the same phone is
// present under multiple accounts, the most recently active conversation wins.
$matchingConvs = array_slice($matchingConvs, 0, 1);

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

    // 3. Invalidate local conversations list cache for this location ID
    try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::deleteRegistry("conversations_registry_{$locId}");
    } catch (\Throwable $cacheEx) {
        error_log("[receive_sms] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    $processed[] = [
        'locId' => $locId,
        'senderNumber' => $senderNumber,
        'message' => $message
    ];
}

// ── Decouple Connection ──────────────────────────────────────────────────
// Send the HTTP response immediately to Semaphore so the webhook completes in <50ms
// This triggers any client-side Firestore listeners instantly without waiting for GHL Sync
if (!headers_sent()) {
    ignore_user_abort(true);
    set_time_limit(300);

    ob_start();
    echo json_encode([
        "status" => "received",
        "locations_processed" => array_column($processed, 'locId')
    ]);
    
    $size = ob_get_length();
    header("Content-Length: $size");
    header("Connection: close");
    ob_end_flush();
    ob_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

// ── Background Execution: Sync to GHL ──────────────────────────────────────
foreach ($processed as $proc) {
    try {
        $ghlSync = new \Nola\Services\GhlSyncService($db, $proc['locId']);
        $ghlSync->syncInboundMessage($proc['senderNumber'], $proc['message']);
    } catch (\Throwable $e) {
        error_log("[receive_sms] GHL Sync failed for location {$proc['locId']}: " . $e->getMessage());
    }
}