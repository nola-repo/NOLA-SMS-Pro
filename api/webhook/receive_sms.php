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

$db->collection('inbound_messages')
    ->document($message_id)
    ->set([
        'message_id'    => $message_id,
        'from'          => $senderNumber,
        'message'       => $message,
        'type'          => 'inbound',
        'date_received' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);

echo json_encode(["status" => "received"]);