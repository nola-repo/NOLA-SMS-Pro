<?php
require_once __DIR__ . '/../cors.php';

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) exit;

$senderNumber = $data['sender'] ?? '';
$message      = $data['message'] ?? '';
$message_id   = $data['message_id'] ?? uniqid();

$db = get_firestore();

// ── Multi-Tenancy: Identify locationId by finding the last sub-account that messaged this contact ────
$locId = null;
$convId = null;

// Search for the most recent conversation where this number is a member
$convQuery = $db->collection('conversations')
    ->where('members', 'array-contains', $senderNumber)
    ->orderBy('last_message_at', 'DESC')
    ->limit(1)
    ->documents();

foreach ($convQuery as $doc) {
    if ($doc->exists()) {
        $convData = $doc->data();
        $locId = $convData['location_id'] ?? null;
        $convId = $doc->id();
        error_log("[receive_sms] Found locationId {$locId} for conversation {$convId} via membership search");
    }
}

if (!$locId) {
    error_log("[receive_sms] No recent conversation found for {$senderNumber}. Inbound message will be unscoped.");
    // Fallback to unscoped conversation ID if no location identified
    $convId = 'conv_' . $senderNumber;
}

$saveData = [
    'message_id'    => $message_id,
    'from'          => $senderNumber,
    'message'       => $message,
    'type'          => 'inbound',
    'date_received' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
];

if ($locId) {
    $saveData['location_id'] = $locId;
}

$db->collection('inbound_messages')
    ->document($message_id)
    ->set($saveData, ['merge' => true]);

echo json_encode(["status" => "received"]);