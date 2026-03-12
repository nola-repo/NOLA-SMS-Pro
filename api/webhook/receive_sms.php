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

// ── Multi-Tenancy: Attempt to find locationId from existing conversation ────
$locId = null;
$convId = 'conv_' . $senderNumber;
$convDoc = $db->collection('conversations')->document($convId)->snapshot();

if ($convDoc->exists()) {
    $locId = $convDoc->data()['location_id'] ?? null;
    error_log("[receive_sms] Found locationId {$locId} for conversation {$convId}");
} else {
    error_log("[receive_sms] No existing conversation found for {$senderNumber}. Inbound message will be unscoped.");
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