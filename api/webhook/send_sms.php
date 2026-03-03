<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';

$SEMAPHORE_API_KEY = $config['SEMAPHORE_API_KEY'];
$SEMAPHORE_URL     = $config['SEMAPHORE_URL'];
$SENDER_IDS        = $config['SENDER_IDS'];

// Simple shared-secret check for webhook calls
$expectedSecret = getenv('WEBHOOK_SECRET') ?: ($config['WEBHOOK_SECRET'] ?? null);
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

if ($expectedSecret && !hash_equals($expectedSecret, $receivedSecret)) {
    http_response_code(401);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Unauthorized',
    ]);
    exit;
}

function log_sms($label, $data)
{
    $log_line = "[" . date('Y-m-d H:i:s') . "] $label: " .
        json_encode($data, JSON_PRETTY_PRINT);

    // Send to Cloud Run logs (Cloud Logging)
    error_log($log_line);
}
function clean_numbers($numberString)
{
    $numbers = explode(',', $numberString);
    $cleanNumbers = [];

    foreach ($numbers as $num) {

        $num = trim($num);
        $num = preg_replace('/\s+/', '', $num);

        if (substr($num, 0, 3) === '+63') {
            $num = '0' . substr($num, 3);
        }

        if (preg_match('/^09\d{9}$/', $num)) {
            $cleanNumbers[] = $num;
        }
    }

    return $cleanNumbers;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $payloadFile = sys_get_temp_dir() . '/last_payload.json';

    if (file_exists($payloadFile)) {
        echo file_get_contents($payloadFile);
    } else {
        echo json_encode([
            "status" => "empty",
            "message" => "No payload yet"
        ]);
    }

    exit;
}

$payload = $_POST;

if (empty($payload)) {

    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);

    if (json_last_error() === JSON_ERROR_NONE) {
        $payload = $decoded;
    }
}

log_sms("INCOMING", $payload);

if (empty($payload)) {
    die(json_encode([
        "status" => "error",
        "message" => "No payload received"
    ]));
}

$customData = $payload['customData'] ?? [];

$number  = $customData['number'] ?? '';
$message = $customData['message'] ?? '';
$sender  = $customData['sendername'] ?? ($SENDER_IDS[0] ?? "");

if (!in_array($sender, $SENDER_IDS)) {
    $sender = $SENDER_IDS[0];
}

$validNumbers = clean_numbers($number);

if (empty($validNumbers)) {
    die(json_encode([
        "status" => "error",
        "message" => "No valid Philippine numbers found"
    ]));
}

$number = implode(',', $validNumbers);
$message = trim($message);

if (empty($message)) {
    die(json_encode([
        "status" => "error",
        "message" => "Message cannot be empty"
    ]));
}

$payloadFile = sys_get_temp_dir() . '/last_payload.json';
file_put_contents(
    $payloadFile,
    json_encode($payload, JSON_PRETTY_PRINT)
);


$sms_data = [
    "apikey"     => $SEMAPHORE_API_KEY,
    "number"     => $number,
    "message"    => $message,
    "sendername" => $sender
];

$ch = curl_init($SEMAPHORE_URL);

curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Accept: application/json"
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_data));

$sms_response = curl_exec($ch);
$http_status  = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if ($sms_response === false) {
    die(json_encode([
        "status" => "error",
        "message" => curl_error($ch)
    ]));
}

$result = json_decode($sms_response, true);


if ($http_status == 200 && is_array($result)) {

    if (isset($result['message_id'])) {

        $message_id = $result['message_id'];
        $status     = $result['status'];

        $db = get_firestore();

        $db->collection('sms_logs')
            ->document($message_id)
            ->set([
                'message_id'   => $message_id,
                'numbers'      => $validNumbers,
                'message'      => $message,
                'sender_id'    => $sender,
                'status'       => $status,
                'date_created' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                'source'       => 'api',
            ], ['merge' => true]);
    }
}
echo json_encode([
    "status" => $http_status == 200 ? "success" : "failed",
    "numbers" => $validNumbers,
    "message" => $message,
    "sender" => $sender,
    "semaphore_status" => $http_status,
    "response" => $result
]);