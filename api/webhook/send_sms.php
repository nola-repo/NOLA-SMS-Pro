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
// MASTER_APPROVED_SENDERS is now loaded dynamically from Firestore (see below after $db init)

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

// ── Dynamic MASTER_APPROVED_SENDERS from Firestore ──────────────────────────
// Replaces the old static config whitelist. Admin-approved senders are auto-added
// to this Firestore doc by admin_sender_requests.php when a request is approved.
$masterSendersSnap = $db->collection('admin_config')->document('master_senders')->snapshot();
$MASTER_APPROVED_SENDERS = $masterSendersSnap->exists()
    ? ($masterSendersSnap->data()['approved_senders'] ?? ['NOLASMSPro'])
    : ['NOLASMSPro'];

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

// ── Sender & Gateway Resolution (single authoritative block) ─────────────────
//
// PATH A — Subaccount has its own (external) API key:
//   → Route through their key. They own their sender registrations.
//   → Skip NOLA credit deduction ($usingCustomSender = true).
//
// PATH B — Using the NOLA master billing gateway:
//   → NOLA credits apply. Sender MUST be in MASTER_APPROVED_SENDERS.
//   → If the subaccount's approved sender is not on the master account,
//     fall back to the default (NOLASMSPro) to guarantee delivery.

$usingCustomSender = false;
$activeApiKey      = $SEMAPHORE_API_KEY;       // Default: master gateway
$sender            = $SENDER_IDS[0] ?? 'NOLASMSPro'; // Default: system sender

$sysKey  = trim((string)$SEMAPHORE_API_KEY);
$userKey = trim((string)($customApiKey ?? ''));

if ($userKey !== '' && $userKey !== $sysKey) {
    // ── PATH A: External API key ─────────────────────────────────────────────
    $usingCustomSender = true;
    $activeApiKey      = $customApiKey;

    // Use their approved sender, or the explicitly requested sender, or the system default.
    if (!empty($approvedSenderId)) {
        $sender = $approvedSenderId;
    } elseif (!empty($requestedSender)) {
        $sender = $requestedSender;
    }
    // else: $sender stays as the system default 'NOLASMSPro'

} else {
    // ── PATH B: Master billing gateway ──────────────────────────────────────
    // If Admin has approved a custom sender for this subaccount, TRUST IT.
    error_log("[send_sms] Resolving Sender ID for Loc: {$locId} (requested: '{$requestedSender}')");
    
    if (!empty($approvedSenderId)) {
        // Safe because it was approved by an Admin in the dashboard
        $sender = $approvedSenderId;
        error_log("[send_sms] Result: Using approved_sender_id '{$sender}' from Firestore.");
    } elseif (!empty($requestedSender) && in_array($requestedSender, $MASTER_APPROVED_SENDERS)) {
        // User requested a specifically approved system name
        $sender = $requestedSender;
        error_log("[send_sms] Result: Using requested whitelist sender '{$sender}'.");
    } else {
        // Fallback to system default
        $sender = $SENDER_IDS[0] ?? 'NOLASMSPro';
        error_log("[send_sms] Result: Fallback to '{$sender}' (approvedSenderId was empty, req='{$requestedSender}').");
        if (!empty($requestedSender) && $requestedSender !== $sender) {
            error_log("[send_sms] Notice: Requested sender '{$requestedSender}' not approved and no subaccount sender exists.");
        }
    }
}

// ── Charging Logic (Quota vs Paid) ───────────────────────────────────────────
// Rules per handoff design:
// - Subaccounts using the NOLA master gateway: free trial applies first, then paid.
// - Subaccounts using their OWN API key (PATH A): skip free trial, go straight to paid.
//   (They route their own SMS costs, but NOLA credits are ALWAYS deducted — no bypass.)
// IMPORTANT: credit count uses $required_credits (actual SMS segments), NOT $num_recipients.

// Free trial only applies on the master gateway (PATH B). Own-API-key senders skip it.
$usingFreeCredits = !$usingCustomSender && ($freeUsageCount + $required_credits <= $freeCreditsTotal);

$creditManager = new CreditManager();
$account_id = $locId ?: 'default';

// ── Debug: Log the billing decision path ─────────────────────────────────────
error_log("[send_sms] BILLING DECISION for loc={$locId}: " . json_encode([
    'usingOwnApiKey'    => $usingCustomSender,   // true = external Semaphore key
    'usingFreeCredits'  => $usingFreeCredits,
    'freeUsageCount'    => $freeUsageCount,
    'freeCreditsTotal'  => $freeCreditsTotal,
    'required_credits'  => $required_credits,
    'num_recipients'    => $num_recipients,
    'account_id'        => $account_id,
    'customApiKey_present' => !empty($customApiKey),
]));

// ── Credit Deduction & Trial ──────────────────────────────────────────────────
if ($usingFreeCredits) {
    // Free Trial (PATH B only) → increment counter, no paid credit deduction
    $intRef->set([
        'free_usage_count' => $freeUsageCount + $required_credits,
        'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
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
    // Paid deduction — applies to ALL sends (both PATH A and non-trial PATH B).
    // Own-API-key users consume NOLA credits for platform usage; free trial is simply skipped.

    // Resolve agency_id for logging and lock check
    $agencyDoc = $db->collection('agency_subaccounts')->document($locId)->snapshot();
    $agency_id = $agencyDoc->exists() ? ($agencyDoc->data()['agency_id'] ?? '') : '';

    // ── 1. Subaccount balance pre-flight ────────────────────────────────────
    $subBalance = $creditManager->get_balance($account_id);
    if ($subBalance <= 0) {
        http_response_code(402);
        echo json_encode([
            'status'             => 'error',
            'error'              => 'insufficient_credits',
            'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
            'subaccount_balance' => $subBalance,
        ]);
        exit;
    }

    // ── 2. Optional master balance lock check ────────────────────────────────
    if ($agency_id && $creditManager->get_agency_master_lock($agency_id)) {
        $agencyBalance = $creditManager->get_agency_balance($agency_id);
        if ($agencyBalance <= 0) {
            http_response_code(402);
            echo json_encode([
                'status'         => 'error',
                'error'          => 'agency_master_lock',
                'message'        => 'Sending is temporarily paused by your agency. Please contact your administrator.',
                'agency_balance' => $agencyBalance,
            ]);
            exit;
        }
    }

    // ── 3. Deduct from subaccount wallet (atomic Firestore txn) ─────────────
    try {
        $desc  = "SMS to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
        $refId = $batch_id ?? ('sms_' . bin2hex(random_bytes(4)));

        // Tag own-API-key sends so the transaction log shows the correct provider
        $provider = $usingCustomSender ? 'semaphore_custom' : 'semaphore';

        $txMetadata = [
            'message_body'    => $message,
            'chars'           => mb_strlen($message, 'UTF-8'),
            'to_number'       => implode(', ', $validNumbers),
            'subaccount_name' => $intData['location_name'] ?? 'Unknown Subaccount',
            'agency_name'     => 'Unknown Agency'
        ];
        if ($agency_id) {
            $agSnap = $db->collection('ghl_tokens')->document($agency_id)->snapshot();
            if ($agSnap->exists() && !empty($agSnap->data()['agency_name'])) {
                $txMetadata['agency_name'] = $agSnap->data()['agency_name'];
            } elseif ($agSnap->exists() && !empty($agSnap->data()['company_name'])) {
                $txMetadata['agency_name'] = $agSnap->data()['company_name'];
            }
        }

        if ($agency_id && $creditManager->get_agency_master_lock($agency_id)) {
            // Master balance lock is ON — deduct from BOTH agency and subaccount wallets.
            // Agency balance must cover the send; if empty, the send is blocked.
            $creditManager->deduct_agency_and_subaccount(
                $account_id,
                $agency_id,
                $required_credits, // Subaccount retail credits
                $required_credits, // Agency wholesale/cost credits
                $refId,
                $desc,
                null,              // provider_cost (dynamic via CreditManager)
                null,              // charged       (dynamic via CreditManager)
                $provider,
                $txMetadata
            );
        } else {
            // No agency master lock — deduct from subaccount wallet only.
            // agency_id is passed for transaction logging/reporting only; no agency balance required.
            $creditManager->deduct_subaccount_only(
                $account_id,
                $agency_id ?: '',
                $required_credits,
                $refId,
                $desc,
                null,
                null,
                $provider,
                $txMetadata
            );
        }
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

    // ── 4. Low Balance Alert ─────────────────────────────────────────────────
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

    $saved_message_ids = [];

    foreach ($all_results as $msg) {
        if (!isset($msg['message_id']))
            continue;

        $messageId = (string)$msg['message_id'];
        $saved_message_ids[] = $messageId;

        $recipientRaw = $msg['number'] ?? $msg['recipient'] ?? $msg['to'] ?? null;
        $recipientArr = $recipientRaw ? clean_numbers($recipientRaw) : [];
        $recipient = $recipientArr[0] ?? $validNumbers[0];

        $sender_id = $sender;
        $recipientKey = $recipient_key ?? $recipient;
        $recipientName = $customData['name'] ?? $recipient;

        // ── Resolve initial status from Semaphore response ─────────────────────
        // Semaphore returns the actual send status in the response (e.g. 'Queued', 'Sent').
        // Use it directly instead of always storing 'Sending', so the frontend
        // sees the real status immediately without waiting for the 5-min cron.
        $rawMsgStatus = strtolower($msg['status'] ?? '');
        $initialStatus = 'Sending';
        if (in_array($rawMsgStatus, ['sent', 'success', 'delivered'])) {
            $initialStatus = 'Sent';
        } elseif (in_array($rawMsgStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
            $initialStatus = 'Failed';
        }
        // 'queued' and 'pending' intentionally stay as 'Sending' — they will be
        // polled by check_message_status.php and resolved quickly.

        $saveData = [
            'conversation_id' => $conversation_id,
            'location_id' => $locId,
            'number' => $recipient,
            'message' => $message,
            'direction' => 'outbound',
            'sender_id' => $sender_id,
            'status' => $initialStatus,
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
            'status' => $initialStatus,
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

        // ── Apply Tags to GHL Contact ────────────────────────────────────────────
        // If the frontend passed tags (via the "Apply Tags" button in the Composer),
        // post them to the GHL Contacts API. Requires a resolved GHL contact ID.
        // This is non-fatal — a tagging failure will never block SMS delivery.
        $tagsToApply = $customData['tagsToApply'] ?? [];
        if (!empty($tagsToApply) && is_array($tagsToApply) && $contactId) {
            try {
                $ghlClient = new GhlClient($db, $locId);
                $tagsResp  = $ghlClient->request(
                    'POST',
                    "/contacts/{$contactId}/tags",
                    json_encode(['tags' => $tagsToApply]),
                    '2021-07-28'
                );
                error_log("[GHL Sync] Applied " . count($tagsToApply) . " tags to contact {$contactId}: " . json_encode($tagsToApply));
            } catch (\Throwable $e) {
                error_log('[GHL Sync] Failed to apply tags (non-fatal): ' . $e->getMessage());
            }
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
    "message" => $sender,
    "execution_log" => "Workflow SMS sent via $sender to $summary. Credits: $required_credits.",
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
        "location_id" => $locId,
        "message_ids" => $saved_message_ids ?? []
    ],
    "debug_info" => [
        "location_id" => $locId,
        "ghl_sync_status" => isset($msgSyncResp) ? $msgSyncResp : "skipped",
        "is_custom_provider" => $usingCustomSender,
        "is_free_trial" => $usingFreeCredits,
        "used_credits" => $required_credits
    ]
]);
