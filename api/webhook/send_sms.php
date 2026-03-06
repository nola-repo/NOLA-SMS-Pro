<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// CORS Headers + Preflight
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: X-Webhook-Secret, Content-Type');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';

$SEMAPHORE_API_KEY = $config['SEMAPHORE_API_KEY'];
$SEMAPHORE_URL = $config['SEMAPHORE_URL'];
$SENDER_IDS = $config['SENDER_IDS'];

validate_api_request();

/* |-------------------------------------------------------------------------- | BASIC LOGGER |-------------------------------------------------------------------------- */
function log_sms($label, $data)
{
    error_log("[" . date('Y-m-d H:i:s') . "] $label: " . json_encode($data));
}

/* |-------------------------------------------------------------------------- | FULL PAYLOAD LOGGER |-------------------------------------------------------------------------- */
function log_full_payload($raw, $payload)
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $debug = [
        "timestamp" => date('Y-m-d H:i:s'),
        "method" => $_SERVER['REQUEST_METHOD'] ?? null,
        "uri" => $_SERVER['REQUEST_URI'] ?? null,
        "headers" => $headers,
        "raw_body" => $raw,
        "json_decoded_payload" => $payload,
        "post_data" => $_POST,
        "get_data" => $_GET
    ];
    error_log("[FULL_PAYLOAD] " . json_encode($debug));
    $payloadFile = sys_get_temp_dir() . '/last_payload_debug.json';
    file_put_contents($payloadFile, json_encode($debug, JSON_PRETTY_PRINT));
}

/* |-------------------------------------------------------------------------- | CLEAN PH NUMBERS |-------------------------------------------------------------------------- */
function clean_numbers($numberString): array
{
    if (!$numberString)
        return [];
    $numbers = is_array($numberString) ? $numberString : preg_split('/[,;]/', $numberString);
    $valid = [];
    foreach ($numbers as $num) {
        $num = trim($num);
        $num = preg_replace('/[^0-9+]/', '', $num);
        $digits = ltrim($num, '+');
        if (preg_match('/^09\d{9}$/', $digits)) {
            $normalized = $digits;
        }
        elseif (preg_match('/^9\d{9}$/', $digits)) {
            $normalized = '0' . $digits;
        }
        elseif (preg_match('/^639\d{9}$/', $digits)) {
            $normalized = '0' . substr($digits, 2);
        }
        elseif (preg_match('/^63(9\d{9})$/', $digits, $m)) {
            $normalized = '0' . $m[1];
        }
        else {
            $normalized = null;
        }
        if ($normalized) {
            $valid[$normalized] = true;
        }
    }
    return array_keys($valid);
}

/* |-------------------------------------------------------------------------- | DEBUG VIEW |-------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $file = sys_get_temp_dir() . '/last_payload_debug.json';
    if (file_exists($file)) {
        echo file_get_contents($file);
    }
    else {
        echo json_encode(["status" => "empty"]);
    }
    exit;
}

/* |-------------------------------------------------------------------------- | RECEIVE PAYLOAD |-------------------------------------------------------------------------- */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
log_full_payload($raw, $payload);

/* |-------------------------------------------------------------------------- | EXTRACT MESSAGE + SENDER |-------------------------------------------------------------------------- */
$customData = $payload['customData'] ?? [];
$data = $payload['data'] ?? [];

$batch_id = $customData['batch_id'] ?? $data['batch_id'] ?? null;
$recipient_key = $customData['recipient_key'] ?? $data['recipient_key'] ?? null;

$message = $customData['message'] ?? $payload['message'] ?? $data['message'] ?? '';

if ($message) {
    $message = strip_tags($message);
    $message = html_entity_decode($message);
    $message = preg_replace('/\s+/', ' ', $message);
    $message = trim($message);
}
log_sms("MESSAGE_CLEANED", $message);

$sender = $customData['sendername'] ?? $payload['sendername'] ?? $data['sendername'] ?? ($SENDER_IDS[0] ?? "");

/* |-------------------------------------------------------------------------- | EXTRACT PHONE NUMBER |-------------------------------------------------------------------------- */
$number_input = $customData['number'] ?? $customData['phone'] ?? $payload['number'] ?? $payload['phone'] ?? $payload['phoneNumber'] ?? ($data['phone'] ?? ($data['Phone'] ?? ($data['number'] ?? ($data['mobile'] ?? ($payload['contact']['phone'] ?? ($payload['contact']['phoneNumber'] ?? ($payload['contact']['mobile'] ?? null)))))));
log_sms("NUMBER_INPUT_RAW", $number_input);

$validNumbers = clean_numbers($number_input);
log_sms("NUMBER_AFTER_CLEAN", $validNumbers);

if (empty($validNumbers)) {
    echo json_encode(["status" => "error", "message" => "No valid PH numbers", "received" => $number_input]);
    exit;
}
if (!$message) {
    echo json_encode(["status" => "error", "message" => "Message empty"]);
    exit;
}

// Auto batch id for bulk sends if not provided
if (!$batch_id && count($validNumbers) > 1) {
    $batch_id = 'batch_' . bin2hex(random_bytes(8));
}

if (!in_array($sender, $SENDER_IDS)) {
    $sender = $SENDER_IDS[0];
}

/* |-------------------------------------------------------------------------- | SEND SMS |-------------------------------------------------------------------------- */
$sms_data = [
    "apikey" => $SEMAPHORE_API_KEY,
    "number" => implode(',', $validNumbers),
    "message" => $message,
    "sendername" => $sender
];
log_sms("SEMAPHORE_REQUEST", $sms_data);

$ch = curl_init($SEMAPHORE_URL);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_data));

$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$result = json_decode($response, true);
log_sms("SEMAPHORE_RESPONSE", $result);

/* |-------------------------------------------------------------------------- | SAVE FIRESTORE |-------------------------------------------------------------------------- */
if ($status == 200 && is_array($result)) {
    $db = get_firestore();
    $now = new \DateTime();
    $ts = new \Google\Cloud\Core\Timestamp($now);
    $messages = isset($result[0]) ? $result : [$result];

    $isBulk = count($validNumbers) > 1 || !empty($batch_id);
    $conversation_id = $isBulk
        ? ('group_' . ($batch_id ?? 'bulk'))
        : ('conv_' . $validNumbers[0]);

    foreach ($messages as $msg) {
        if (!isset($msg['message_id']))
            continue;

        $recipientRaw = $msg['number'] ?? $msg['recipient'] ?? $msg['to'] ?? null;
        $recipientArr = $recipientRaw ? clean_numbers($recipientRaw) : [];
        $recipient = $recipientArr[0] ?? $validNumbers[0];

        $db->collection('messages')
            ->document($msg['message_id'])
            ->set([
            'conversation_id' => $conversation_id,
            'number' => $recipient,
            'message' => $message,
            'sender_id' => $sender,
            'direction' => 'outbound',
            'status' => $msg['status'] ?? 'sent',
            'batch_id' => $batch_id,
            'recipient_key' => $recipient_key ?? $recipient,
            'created_at' => $ts,
            'date_created' => $ts,
        ], ['merge' => true]);
    }

    // Conversation doc for UI sidebar
    $db->collection('conversations')
        ->document($conversation_id)
        ->set([
        'id' => $conversation_id,
        'type' => $isBulk ? 'bulk' : 'direct',
        'members' => $validNumbers,
        'last_message' => $message,
        'last_message_at' => $ts,
        'name' => $isBulk ? ($customData['campaign_name'] ?? $batch_id ?? 'Bulk') : ($customData['name'] ?? $validNumbers[0]),
        'updated_at' => $ts,
    ], ['merge' => true]);
}

echo json_encode([
    "status" => $status == 200 ? "success" : "failed",
    "numbers" => $validNumbers,
    "message" => $message,
    "sender" => $sender,
    "batch_id" => $batch_id,
    "response" => $result
]);
