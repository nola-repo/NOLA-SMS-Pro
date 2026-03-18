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
/** @deprecated Use CreditManager::calculateRequiredCredits() */
function calculate_credits($message, $num_recipients)
{
    return CreditManager::calculateRequiredCredits($message, $num_recipients);
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

// Extract Numbers
$numberRaw = $customData['number'] ?? $payload['number'] ?? $data['number'] ?? $payload['phone'] ?? null;
$validNumbers = clean_numbers($numberRaw);
$num_recipients = count($validNumbers);

if ($num_recipients === 0) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "No valid Philippine mobile numbers provided."]);
    exit;
}

// Calculate Credits
$required_credits = CreditManager::calculateRequiredCredits($message, $num_recipients);

// ── Multi-Tenancy: Get and Validate locationId ──────────────────────────────────
$locId = get_ghl_location_id();
if (!$locId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing location_id (X-GHL-Location-ID header or query param required)']);
    exit;
}
$account_id = $locId ?: 'default';

$db = get_firestore();
$accountDoc = $db->collection('accounts')->document($account_id)->snapshot();
$accountData = $accountDoc->exists() ? $accountDoc->data() : [];

$approvedSenderId = $accountData['approved_sender_id'] ?? null;
// Support legacy semaphore_api_key but prefer the new nola_pro_api_key
$customApiKey = $accountData['nola_pro_api_key'] ?? ($accountData['semaphore_api_key'] ?? null);
$freeUsageCount = $accountData['free_usage_count'] ?? 0;

// Sender ID Logic: prioritize approved custom sender + custom valid API key
if ($approvedSenderId && $customApiKey) {
    $sender = $approvedSenderId;
    $activeApiKey = $customApiKey;
}
else {
    // Only block if they try to send > 10 messages but haven't bought/setup an API key yet
    if ($freeUsageCount >= 10) {
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Free message limit reached (10/10). Registration of a custom Sender ID and API Key is required."]);
        exit;
    }
    // Fallback to system default
    $sender = $customData['sendername'] ?? $payload['sendername'] ?? $data['sendername'] ?? ($SENDER_IDS[0] ?? "");
    if (!in_array($sender, $SENDER_IDS)) {
        $sender = $SENDER_IDS[0];
    }
    $activeApiKey = $SEMAPHORE_API_KEY;
}

$creditManager = new CreditManager();

try {
    $creditManager->deduct_credits(
        $account_id,
        $required_credits,
        $batch_id ?? ('single_' . bin2hex(random_bytes(4))),
        "SMS sent to $num_recipients recipients"
    );

    // Increment free usage count if using system default (no approved custom sender + key combo)
    if (!($approvedSenderId && $customApiKey)) {
        $db->collection('accounts')->document($account_id)->set([
            'free_usage_count' => $freeUsageCount + $num_recipients
        ], ['merge' => true]);
    }
}
catch (\Exception $e) {
    if ($e->getMessage() === "Insufficient credits.") {
        echo json_encode(["status" => "error", "message" => "Insufficient credits"]);
    }
    else {
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
        "apikey" => $activeApiKey,
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

    $isBulk = count($validNumbers) > 1;
    $prefix = $locId . '_';

    $conversation_id = $isBulk
        ? ($prefix . 'group_' . ($batch_id ?? 'bulk'))
        : ($prefix . 'conv_' . $validNumbers[0]);

    // Calculate credits per message for logging
    $credits_per_message = CreditManager::calculateRequiredCredits($message, 1);

    foreach ($all_results as $msg) {
        if (!isset($msg['message_id']))
            continue;

        $messageId = (string)$msg['message_id'];

        $recipientRaw = $msg['number'] ?? $msg['recipient'] ?? $msg['to'] ?? null;
        $recipientArr = $recipientRaw ? clean_numbers($recipientRaw) : [];
        $recipient = $recipientArr[0] ?? $validNumbers[0];

        $sender_id = $sender; // Assuming $sender is already defined
        $recipientKey = $recipient_key ?? $recipient; // Assuming $recipient_key is already defined
        $recipientName = $customData['name'] ?? $recipient; // Assuming $customData is defined

        $saveData = [
            'conversation_id' => $conversation_id,
            'location_id' => $locId,
            'number' => $recipient,
            'message' => $message,
            'direction' => 'outbound',
            'sender_id' => $sender_id,
            'status' => $msg['status'] ?? 'Queued',
            'batch_id' => $batch_id,
            'recipient_key' => $recipientKey,
            'created_at' => $ts,
            'date_created' => $ts,
            'name' => $recipientName,
            'message_id' => $messageId,
            'segments' => $credits_per_message
        ];

        $db->collection('messages')
            ->document($messageId)
            ->set($saveData, ['merge' => true]);

        // Legacy/History log (Web UI currently reads outbound history from sms_logs)
        // Also keeps retrieve_status.php working (it polls sms_logs where status is Pending/Queued).
        $logData = [
            'message_id' => $messageId,
            'numbers' => [$recipient],
            'message' => $message,
            'sender_id' => $sender,
            'status' => $msg['status'] ?? 'Queued',
            'date_created' => $ts,
            'source' => 'semaphore',
            'batch_id' => $batch_id,
            'recipient_key' => $recipient_key ?? $recipient,
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
        'location_id' => $locId,
        'last_message' => $message,
        'last_message_at' => $ts,
        'updated_at' => $ts,
        'members' => $validNumbers,
        'name' => $isBulk ? 'Bulk Campaign' : ($recipientName ?: $validNumbers[0]),
        'type' => $isBulk ? 'group' : 'direct'
    ];

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
