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
    error_log("[receive_sms] FAILED: No recent conversation or GHL association found for {$senderNumber}. Dropping inbound message to prevent cross-account bleeding.");
    exit(json_encode(["status" => "ignored", "reason" => "unmapped_sender"]));
}

$saveData = [
    'conversation_id' => $convId,
    'location_id'     => $locId,
    'message_id'      => $message_id,
    'from'            => $senderNumber,
    'message'         => $message,
    'direction'       => 'inbound',
    'status'          => 'Received',
    'date_received'   => new \Google\Cloud\Core\Timestamp(new \DateTime()),
];

// 1. Store in inbound_messages (compatibility)
$db->collection('inbound_messages')->document($message_id)->set($saveData, ['merge' => true]);

// 2. Store in messages (unified thread)
$db->collection('messages')->document($message_id)->set($saveData, ['merge' => true]);

// 3. Update Sidebar (conversations)
$now = new \Google\Cloud\Core\Timestamp(new \DateTime());
$convDocRef = $db->collection('conversations')->document($convId);
$convDocRef->set([
    'id'              => $convId,
    'location_id'     => $locId,
    'last_message'    => $message,
    'last_message_at' => $now,
    'updated_at'      => $now,
    'type'            => 'direct',
    'members'         => [$senderNumber]
], ['merge' => true]);

echo json_encode(["status" => "received", "location_id" => $locId, "conversation_id" => $convId]);