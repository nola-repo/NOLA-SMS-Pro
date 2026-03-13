<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require __DIR__ . '/../services/CreditManager.php';

$SEMAPHORE_API_KEY = $config['SEMAPHORE_API_KEY'];
$SEMAPHORE_URL = $config['SEMAPHORE_URL'];
$SENDER_IDS = $config['SENDER_IDS'];

validate_api_request();

function log_sms($label, $data)
{
    error_log("[" . date('Y-m-d H:i:s') . "] $label: " . json_encode($data));
}

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

/* |-------------------------------------------------------------------------- | CREDIT CALCULATION |-------------------------------------------------------------------------- */
function calculate_credits($message, $num_recipients)
{
    $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
    $segments = max(1, ceil($length / 160));
    return (int)($segments * $num_recipients);
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

$batch_id = $customData['batch_id'] ?? $data['batch_id'] ?? $payload['batch_id'] ?? $_POST['batch_id'] ?? null;
$recipient_key = $customData['recipient_key'] ?? $data['recipient_key'] ?? $payload['recipient_key'] ?? $_POST['recipient_key'] ?? null;

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
    echo json_encode(["status" => "error", "message" => "No valid Philippine numbers found", "received" => $number_input]);
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

/* |-------------------------------------------------------------------------- | CREDIT CHECK & DEDUCTION |-------------------------------------------------------------------------- */
$num_recipients = count($validNumbers);
$required_credits = calculate_credits($message, $num_recipients);
$creditManager = new CreditManager();
$locId = get_ghl_location_id();
$account_id = $locId ?: 'default';

try {
    $creditManager->deduct_credits(
        $account_id, 
        $required_credits, 
        $batch_id ?? ('single_' . bin2hex(random_bytes(4))), 
        "SMS sent to $num_recipients recipients"
    );
} catch (\Exception $e) {
    if ($e->getMessage() === "Insufficient credits.") {
        echo json_encode(["status" => "error", "message" => "Insufficient credits"]);
    } else {
        echo json_encode(["status" => "error", "message" => "Credit deduction failed: " . $e->getMessage()]);
    }
    exit;
}

/* |-------------------------------------------------------------------------- | SEND SMS (BATCHED) |-------------------------------------------------------------------------- */
$chunks = array_chunk($validNumbers, 500);
$all_results = [];
$total_status = 200;

foreach ($chunks as $chunk) {
    $sms_data = [
        "apikey" => $SEMAPHORE_API_KEY,
        "number" => implode(',', $chunk),
        "message" => $message,
        "sendername" => $sender
    ];
    log_sms("SEMAPHORE_REQUEST_CHUNK", ["chunk_size" => count($chunk)]);

    $ch = curl_init($SEMAPHORE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_data));

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($status != 200) {
        $total_status = $status;
    }
    $result = json_decode($response, true);
    log_sms("SEMAPHORE_RESPONSE_CHUNK", $result);
    
    if (is_array($result)) {
        $all_results = array_merge($all_results, $result);
    }
}

/* |-------------------------------------------------------------------------- | SAVE FIRESTORE |-------------------------------------------------------------------------- */
if (!empty($all_results)) {
    $db = get_firestore();
    $now = new \DateTime();
    $ts = new \Google\Cloud\Core\Timestamp($now);

    $isBulk = count($validNumbers) > 1 || !empty($batch_id);
    $conversation_id = $isBulk
        ? ('group_' . ($batch_id ?? 'bulk'))
        : ('conv_' . $validNumbers[0]);

    // Calculate credits per message for logging
    $credits_per_message = calculate_credits($message, 1);

    foreach ($all_results as $msg) {
        if (!isset($msg['message_id']))
            continue;

        $messageId = (string)$msg['message_id'];

        $recipientRaw = $msg['number'] ?? $msg['recipient'] ?? $msg['to'] ?? null;
        $recipientArr = $recipientRaw ? clean_numbers($recipientRaw) : [];
        $recipient = $recipientArr[0] ?? $validNumbers[0];

        $msgData = [
            'conversation_id' => $conversation_id,
            'number' => $recipient,
            'message' => $message,
            'sender_id' => $sender,
            'direction' => 'outbound',
            'status' => $msg['status'] ?? 'Queued',
            'batch_id' => $batch_id,
            'recipient_key' => $recipient_key ?? $recipient,
            'credits_used' => $credits_per_message,
            'created_at' => $ts,
            'date_created' => $ts,
        ];

        if ($locId) {
            $msgData['location_id'] = $locId;
        }

        $db->collection('messages')
            ->document($messageId)
            ->set($msgData, ['merge' => true]);

        // Legacy/History log (Web UI currently reads outbound history from sms_logs)
        // Also keeps retrieve_status.php working (it polls sms_logs where status is Pending/Queued).
        $logData = [
            'message_id'   => $messageId,
            'numbers'      => [$recipient],
            'message'      => $message,
            'sender_id'    => $sender,
            'status'       => $msg['status'] ?? 'Queued',
            'date_created' => $ts,
            'source'       => 'semaphore',
            'batch_id'     => $batch_id,
            'recipient_key'=> $recipient_key ?? $recipient,
            'credits_used' => $credits_per_message,
            'conversation_id' => $conversation_id,
        ];

        if ($locId) {
            $logData['location_id'] = $locId;
        }

        $db->collection('sms_logs')
            ->document($messageId)
            ->set($logData, ['merge' => true]);
    }

    $convData = [
        'id' => $conversation_id,
        'type' => $isBulk ? 'bulk' : 'direct',
        'members' => $validNumbers,
        'last_message' => $message,
        'last_message_at' => $ts,
        'name' => $isBulk ? ($customData['campaign_name'] ?? $batch_id ?? 'Bulk') : ($customData['name'] ?? $validNumbers[0]),
        'updated_at' => $ts,
    ];

    if ($locId) {
        $convData['location_id'] = $locId;
    }

    // Conversation doc for UI sidebar
    $db->collection('conversations')
        ->document($conversation_id)
        ->set($convData, ['merge' => true]);
}

echo json_encode([
    "status" => $total_status == 200 ? "success" : "partial_or_failed",
    "numbers" => $validNumbers,
    "message" => $message,
    "sender" => $sender,
    "batch_id" => $batch_id,
    "credits_deducted" => $required_credits,
    "response" => $all_results
]);
