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
require __DIR__ . '/../services/GhlClient.php';
require_once __DIR__ . '/../services/GhlSyncService.php';


$SEMAPHORE_API_KEY = $config['SEMAPHORE_API_KEY'];
$SEMAPHORE_URL = $config['SEMAPHORE_URL'];
$SENDER_IDS = $config['SENDER_IDS'];

// --- Maintenance Mode Check ---
$db_maintenance = get_firestore();
$globalConfigRef = $db_maintenance->collection('admin_config')->document('global');
$globalConfigSnap = $globalConfigRef->snapshot();
if ($globalConfigSnap->exists() && !empty($globalConfigSnap->data()['maintenance_mode'])) {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'message' => 'System is currently in maintenance mode.']);
    exit;
}
// ------------------------------

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

// GHL Contact ID — passed by GHL Workflows as {{contact.id}} in customData.
// Used by the GHL sync block below to post the message back to GHL Conversations.
$contactId = $customData['contactId'] ?? $customData['contact_id']
    ?? $data['contactId'] ?? $data['contact_id']
    ?? $payload['contactId'] ?? $payload['contact_id'] ?? null;

$message = $customData['message'] ?? $payload['message'] ?? $data['message'] ?? '';

if ($message) {
    $message = strip_tags($message);
    $message = html_entity_decode($message);
    
    // Sanitize smart unicode punctuation to GSM-7 equivalents to prevent UCS-2 segment limits
    $message = str_replace(
        ['‘', '’', '“', '”', '–', '—', '…', '`', '´'],
        ["'", "'", '"', '"', '-', '-', '...', "'", "'"],
        $message
    );

    $message = preg_replace('/\s+/', ' ', $message);
    $message = trim($message);
}
log_sms("MESSAGE_CLEANED", $message);

// Extract Numbers — GHL Marketplace may send as 'number' or 'phone' depending on field reference
$numberRaw = $customData['number'] ?? $customData['phone'] ?? $payload['number'] ?? $data['number'] ?? $payload['phone'] ?? $data['phone'] ?? null;
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
// GHL does NOT interpolate {{variables}} in custom HTTP headers for Marketplace actions —
// only in the request body. So we check the header first, then fall back to the body.
$locId = get_ghl_location_id();
if (!$locId) {
    // Fallback: read location_id from common GHL payload fields
    $locId = $customData['location_id'] ?? $customData['locationId'] 
        ?? $payload['location_id'] ?? $payload['locationId']
        ?? $data['location_id'] ?? $data['locationId'] ?? null;
    
    // Clean and Sanitise
    if ($locId) {
        $locId = trim((string)$locId);
        if (strpos($locId, '{{') !== false) {
            $locId = null;
        }
    }
}
if (!$locId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing location_id. Pass it via X-GHL-Location-ID header or as location_id in the request body.']);
    exit;
}

$db = get_firestore();
$intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
$intRef = $db->collection('integrations')->document($intDocId);
$intSnap = $intRef->snapshot();
$intData = $intSnap->exists() ? $intSnap->data() : [];

// ── Check Agency Toggle & Rate Limits ─────────────────────────────────────
$tokenRef = $db->collection('ghl_tokens')->document($locId);
$tokenSnap = $tokenRef->snapshot();
$tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];

$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;
$rateLimit = isset($tokenData['rate_limit']) ? (int)$tokenData['rate_limit'] : 0;
$attemptCount = isset($tokenData['attempt_count']) ? (int)$tokenData['attempt_count'] : 0;
$lastReset = $tokenData['last_reset_date'] ?? '';

if (!$toggleEnabled) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
}

$today = date('Y-m-d');
// Daily Reset Logic applied to ghl_tokens
if ($lastReset !== $today) {
    $attemptCount = 0;
    $tokenRef->set([
        'attempt_count' => 0,
        'last_reset_date' => $today
    ], ['merge' => true]);
}

// Block if limit reached
if ($rateLimit > 0 && $attemptCount >= $rateLimit) {
    http_response_code(403);
    echo json_encode([
        "status" => "error", 
        "error"  => "rate_limit_reached",
        "message" => "Agency subaccount credit limit exceeded ($rateLimit)."
    ]);
    exit;
}

// Atomically reserve an attempt
if ($rateLimit > 0 || isset($tokenData['rate_limit'])) {
    $tokenRef->set([
        'attempt_count' => \Google\Cloud\Firestore\FieldValue::increment(1)
    ], ['merge' => true]);
}

$approvedSenderId = $intData['approved_sender_id'] ?? null;
// Support legacy semaphore_api_key but prefer the new nola_pro_api_key
$customApiKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
$freeUsageCount = $intData['free_usage_count'] ?? 0;
$freeCreditsTotal = $intData['free_credits_total'] ?? 10;

// Extract the sendername the frontend/user selected
$requestedSender = $customData['sendername'] ?? $payload['sendername'] ?? $data['sendername'] ??
    $customData['sender_name'] ?? $payload['sender_name'] ?? $data['sender_name'] ?? null;

// ── Credit & Sender Selection Logic ─────────────────────────────────────────
// Delivery selection (who sends the SMS) vs Charging selection (how it's paid for)

// 1. Delivery Selection (Carrier/Provider)
$usingCustomSender = false;
$activeApiKey = $SEMAPHORE_API_KEY;
$sender = $SENDER_IDS[0] ?? "";

// Delivery Selection: Sender Name
if ($approvedSenderId && ($requestedSender === $approvedSenderId || empty($requestedSender))) {
    $sender = $approvedSenderId;
} else if ($requestedSender && in_array($requestedSender, $SENDER_IDS)) {
    $sender = $requestedSender;
}

// Delivery Selection: API Key / Gateway
if ($customApiKey) {
    $activeApiKey = $customApiKey;
    
    // Billing Policy: Skip deduction only if the API key is truly EXTERNAL.
    $sysKey = trim((string)$SEMAPHORE_API_KEY);
    $userKey = trim((string)$customApiKey);

    if ($userKey !== "" && $userKey !== $sysKey) {
        $usingCustomSender = true;
    }
}

// 3. Sender ID Logic
// We trust the approved_sender_id from Firestore.
if ($approvedSenderId && ($requestedSender === $approvedSenderId || empty($requestedSender))) {
    $sender = $approvedSenderId;
}

// 2. Charging Logic (Quota vs Paid)
// Charging depends ONLY on trial quota (free_usage_count), NOT on carrier choice.
// IMPORTANT: Compare with $required_credits (actual SMS segments), NOT $num_recipients.
$usingFreeCredits = ($freeUsageCount + $required_credits <= $freeCreditsTotal);

$creditManager = new CreditManager();
$account_id = $locId ?: 'default';

// ── Debug: Log the billing decision path ─────────────────────────────────────
error_log("[send_sms] BILLING DECISION for loc={$locId}: " . json_encode([
    'usingCustomSender' => $usingCustomSender,
    'usingFreeCredits' => $usingFreeCredits,
    'freeUsageCount' => $freeUsageCount,
    'freeCreditsTotal' => $freeCreditsTotal,
    'required_credits' => $required_credits,
    'num_recipients' => $num_recipients,
    'account_id' => $account_id,
    'customApiKey_present' => !empty($customApiKey),
]));

// ── Credit Deduction & Trial ─────────────────────────────────────────────────
// Architecture: Single-deduction.
// Skip deduction if using a CUSTOM API key (already handled by $usingCustomSender policy).
if ($usingCustomSender) {
    error_log("[send_sms] BILLING PATH: CustomSender — skipping deduction for loc={$locId}");
} else if ($usingFreeCredits) {
    // Free Trial → increment counter only, no paid credit deduction
    $intRef->set([
        'free_usage_count' => $freeUsageCount + $required_credits,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);

    try {
        $desc = "SMS (Trial) to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
        $creditManager->record_trial_usage(
            $account_id,
            $required_credits,
            $batch_id ?? ('trial_' . bin2hex(random_bytes(4))),
            $desc
        );
    } catch (\Exception $e) {
        error_log("Trial logging failed: " . $e->getMessage());
    }
} else {
    // Paid send — look up agency_id for logging and lock check
    $agencyDoc = $db->collection('agency_subaccounts')->document($locId)->snapshot();
    $agency_id = $agencyDoc->exists() ? ($agencyDoc->data()['agency_id'] ?? '') : '';

    // ── 1. Subaccount balance pre-flight ────────────────────────────────
    $subBalance = $creditManager->get_balance($account_id);
    if ($subBalance <= 0) {
        http_response_code(402);
        echo json_encode([
            'status'              => 'error',
            'error'               => 'insufficient_credits',
            'message'             => 'Your account has no credits. Please top up or request credits from your agency.',
            'subaccount_balance'  => $subBalance,
        ]);
        exit;
    }

    // ── 2. Optional master balance lock check ────────────────────────────
    if ($agency_id && $creditManager->get_agency_master_lock($agency_id)) {
        $agencyBalance = $creditManager->get_agency_balance($agency_id);
        if ($agencyBalance <= 0) {
            http_response_code(402);
            echo json_encode([
                'status'          => 'error',
                'error'           => 'agency_master_lock',
                'message'         => 'Sending is temporarily paused by your agency. Please contact your administrator.',
                'agency_balance'  => $agencyBalance,
            ]);
            exit;
        }
    }

    // ── 3. Deduct ONLY from subaccount wallet (atomic Firestore txn) ─────
    try {
        $desc = "SMS to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
        $refId = $batch_id ?? ('sms_' . bin2hex(random_bytes(4)));

        $creditManager->deduct_subaccount_only(
            $account_id,
            $agency_id,
            $required_credits,
            $refId,
            $desc,
            0.02,        // provider_cost
            0.05,        // charged
            'semaphore'  // provider
        );
    } catch (\Exception $e) {
        $errData = json_decode($e->getMessage(), true) ?: null;
        if ($errData && ($errData['error'] ?? '') === 'insufficient_credits') {
            http_response_code(402);
            echo json_encode([
                'status'             => 'error',
                'error'              => 'insufficient_credits',
                'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
                'subaccount_balance' => $errData['subaccount_balance'] ?? null,
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Credit deduction failed: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── 4. Low Balance Alert ─────────────────────────────────────────────
    try {
        require_once __DIR__ . '/../services/NotificationService.php';
        $newBalance = $creditManager->get_balance($account_id);
        NotificationService::checkLowBalance($db, $locId, $newBalance);
    } catch (\Throwable $e) {
        error_log('[LowBalanceAlert] ' . $e->getMessage());
    }
}

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
            'status' => 'Sending',
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
            'status' => 'Sending',
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

    // ── GHL Bidirectional Sync (Best-Effort) ─────────────────────────────────
    if (!$isBulk && $locId) {
        try {
            $ghlSync = new \Nola\Services\GhlSyncService($db, $locId);
            $ghlSync->syncOutboundMessage($validNumbers[0], $message, $contactId);
        } catch (\Throwable $e) {
            error_log('[GHL Sync] Failed (non-fatal): ' . $e->getMessage());
        }
    }

// ── End GHL Sync ──────────────────────────────────────────────────────────
}

// GHL-friendly log structure
$ghlStatus = $total_status == 200 ? "success" : "error";
$summary = "Sent to " . implode(', ', $validNumbers);
if (count($validNumbers) > 3) {
    $summary = "Sent to " . count($validNumbers) . " recipients";
}

// GHL Legacy/Success response structure
echo json_encode([
    "status" => $ghlStatus,
    "message" => "NOLA SMS Pro",
    "execution_log" => "Workflow SMS sent via NOLASMSPro to $summary. Credits: $required_credits.",
    "action_executed_from" => "Nola Web",
    "event_details" => [
        "Status" => "Success",
        "Recipient(s)" => implode(', ', $validNumbers),
        "SMS Message" => $message,
        "Credits Used" => $required_credits,
        "Sender ID" => $sender,
        "Location ID" => $locId,
        "Timestamp" => date('Y-m-d H:i:s')
    ],
    "output" => [
        "success" => ($total_status == 200),
        "summary" => $summary,
        "credits" => $required_credits,
        "location_id" => $locId
    ],
    "debug_info" => [
        "location_id" => $locId,
        "ghl_sync_status" => isset($msgSyncResp) ? $msgSyncResp : "skipped",
        "is_custom_provider" => $usingCustomSender,
        "is_free_trial" => $usingFreeCredits,
        "used_credits" => $required_credits
    ]
]);
