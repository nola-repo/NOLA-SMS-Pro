<?php

require_once __DIR__ . '/../cors.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlSyncService.php';
require_once __DIR__ . '/../auth_helpers.php';

// Requires ?secret= to be appended to the webhook URL
validate_api_request();

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    exit;
}

error_log("[receive_sms_unisms] Inbound webhook payload: " . $raw);

// Parse UniSMS webhook format
// Inbound messages typically have message details inside a nested object
$messageObj = $data['message'] ?? [];
$senderRaw = $messageObj['recipient'] ?? $messageObj['sender'] ?? $messageObj['number'] ?? $data['sender'] ?? '';
$message = $messageObj['content'] ?? $messageObj['message'] ?? $data['message'] ?? '';
$message_id = $data['id'] ?? $messageObj['reference_id'] ?? $messageObj['id'] ?? uniqid('unisms_in_');

// Clean Sender Number
function clean_inbound_number($number): string
{
    $digits = preg_replace('/\D/', '', $number);
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return '0' . substr($digits, 2);
    }
    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return $digits;
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '0' . $digits;
    }
    return $digits;
}

$senderNumber = clean_inbound_number($senderRaw);

if (empty($senderNumber) || empty($message)) {
    error_log("[receive_sms_unisms] Skipped: missing senderNumber ({$senderNumber}) or message ({$message})");
    exit(json_encode(["status" => "error", "reason" => "missing_data"]));
}

$db = get_firestore();

// ── Multi-Tenancy: Search for matching conversations ────────────────────────
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
    error_log("[receive_sms_unisms] FAILED: No recent conversation found for {$senderNumber}. Ignored to prevent cross-account bleeding.");
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

    if (!$locId || !$convId) {
        continue;
    }

    $saveData = [
        'conversation_id' => $convId,
        'location_id' => $locId,
        'message_id' => $message_id . '_' . $locId, // Unique per location
        'from' => $senderNumber,
        'message' => $message,
        'direction' => 'inbound',
        'status' => 'Received',
        'date_received' => $now,
        'provider' => 'unisms'
    ];

    // 1. Store message in Firestore
    $db->collection('messages')->document($saveData['message_id'])->set($saveData, ['merge' => true]);

    // 2. Update Sidebar conversation record
    $db->collection('conversations')->document($convId)->set([
        'id' => $convId,
        'location_id' => $locId,
        'last_message' => $message,
        'last_message_at' => $now,
        'updated_at' => $now,
        'type' => 'direct',
        'members' => [$senderNumber]
    ], ['merge' => true]);

    // 3. Invalidate UI conversations list cache
    try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::deleteRegistry("conversations_registry_{$locId}");
    } catch (\Throwable $cacheEx) {
        error_log("[receive_sms_unisms] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    $processed[] = [
        'locId' => $locId,
        'senderNumber' => $senderNumber,
        'message' => $message
    ];
}

// ── Decouple connection for instant client updates ─────────────────────────
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
        error_log("[receive_sms_unisms] GHL Sync failed for location {$proc['locId']}: " . $e->getMessage());
    }
}
