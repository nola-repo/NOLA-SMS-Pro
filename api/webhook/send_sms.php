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
    // Fallback: read location_id from the JSON body (where GHL DOES replace {{location.id}})
    $locId = $customData['location_id'] ?? $payload['location_id'] ?? $data['location_id'] ?? null;
    // Sanitise: if it still looks like an un-replaced template, treat as missing
    if ($locId && strpos((string)$locId, '{{') !== false) {
        $locId = null;
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

// ── Check Agency Toggle ───────────────────────────────────────────────────
$tokenRef = $db->collection('ghl_tokens')->document($locId);
$tokenSnap = $tokenRef->snapshot();
$tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

if (!$toggleEnabled) {
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
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

if ($approvedSenderId && $customApiKey && ($requestedSender === $approvedSenderId || empty($requestedSender))) {
    // Custom Sender: Use customer's API key and approved sender name
    $sender = $approvedSenderId;
    $activeApiKey = $customApiKey;
    $usingCustomSender = true;
} else {
    // System Sender: Use system API key and selected/default sender name
    if ($requestedSender && in_array($requestedSender, $SENDER_IDS)) {
        $sender = $requestedSender;
    }
}

// 2. Charging Logic (Quota vs Paid)
// Charging depends ONLY on trial quota (free_usage_count), NOT on carrier choice.
$usingFreeCredits = ($freeUsageCount + $num_recipients <= $freeCreditsTotal);


$creditManager = new CreditManager();
$account_id = $locId ?: 'default';

// ── Agency myCRMSIM Routing Check ──────────────────────────────────────────
$useMyCrmSim = false;
if ($locId) {
    $agencySubRef = $db->collection('agency_subaccounts')->document($locId);
    $agencySubSnap = $agencySubRef->snapshot();
    if ($agencySubSnap->exists()) {
        $agencySubData = $agencySubSnap->data();
        if (!empty($agencySubData['toggle_enabled'])) {
            $today = date('Y-m-d');
            $lastReset = $agencySubData['last_reset_date'] ?? '';
            $attempt_count = $agencySubData['attempt_count'] ?? 0;
            $rate_limit = $agencySubData['rate_limit'] ?? 0;

            // Daily Reset Logic
            if ($lastReset !== $today) {
                $attempt_count = 0;
                $agencySubRef->set([
                    'attempt_count' => 0,
                    'last_reset_date' => $today
                ], ['merge' => true]);
            }

            if ($attempt_count < $rate_limit) {
                $useMyCrmSim = true;
                // --- FIX 1: Atomic Increment to prevent race conditions ---
                $agencySubRef->set([
                    'attempt_count' => \Google\Cloud\Firestore\FieldValue::increment(1)
                ], ['merge' => true]);
            } else {
                http_response_code(403);
                echo json_encode(["status" => "error", "message" => "Agency subaccount daily rate limit exceeded ($rate_limit)."]);
                exit;
            }
        }
    }
}

// ── Credit Deduction & Trial ────────────────────────────────────────────────
// Credits are charged if NOT using myCRMSIM (hardware bypass)
if (!$useMyCrmSim) {
    if ($usingFreeCredits) {

        // Tier 2: Free Trial -> Increment free usage counter, do NOT deduct paid balance
        $intRef->set([
            'free_usage_count' => $freeUsageCount + $num_recipients,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        // LOGGING: Record trial usage in transaction history for visibility (amount 0)
        try {
            $desc = "SMS Message to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
            $creditManager->record_trial_usage(
                $account_id,
                $required_credits,
                $batch_id ?? ('trial_' . bin2hex(random_bytes(4))),
                $desc
            );
        } catch (\Exception $e) {
            error_log("Trial logging failed: " . $e->getMessage());
            // We don't exit here since the free counter was already updated
        }
    } else {
        // Tier 3: Paid Usage -> Deduct actual paid credits
        try {
            $desc = "SMS Message to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
            $creditManager->deduct_credits(
                $account_id,
                $required_credits,
                $batch_id ?? ('single_' . bin2hex(random_bytes(4))),
                $desc
            );
        }
        catch (\Exception $e) {
            if ($e->getMessage() === "Insufficient credits.") {
                // Both free trial exhausted AND paid credits insufficient → hard block
                http_response_code(403);
                echo json_encode([
                    "status" => "error",
                    "message" => "Insufficient credits to send SMS. Please top up your credits.",
                    "error" => "insufficient_credits"
                ]);
            }
            else {
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Credit deduction failed: " . $e->getMessage()]);
            }
            exit;
        }

        // ── Low Balance Alert ──────────────────────────────────────────────────
        try {
            require_once __DIR__ . '/../services/NotificationService.php';
            $newBalance = $creditManager->get_balance($account_id);
            NotificationService::checkLowBalance($db, $locId, $newBalance);
        }
        catch (\Throwable $e) {
            error_log('[LowBalanceAlert] ' . $e->getMessage());
        }
    }
}

/* |-------------------------------------------------------------------------- | SEND SMS (BATCHED) |-------------------------------------------------------------------------- */
$chunks = array_chunk($validNumbers, 500);
$all_results = [];
$total_status = 200;

$myCrmSimSuccess = false;
if ($useMyCrmSim) {
    // ── Send via myCRMSIM ───────────────────────────────────────────────────
    $myCrmSimToken = $config['MYCRMSIM_API_KEY'] ?? '';
    $myCrmSimUrl = $config['MYCRMSIM_URL'] ?? 'https://r6bszuuso6.execute-api.ap-southeast-2.amazonaws.com/prod/webhook';

    foreach ($validNumbers as $num) {
        $msgId = 'mycrmsim_' . bin2hex(random_bytes(6));
        $sms_data = [
            "location_id" => $locId,
            "message_id" => $msgId,
            "channel" => "SMS",
            "phone" => $num,
            "message" => $message
        ];

        $ch = curl_init($myCrmSimUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json",
            "Authorization: Bearer " . $myCrmSimToken
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($sms_data));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); 
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); 
        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($status == 200 || $status == 201) {
            $myCrmSimSuccess = true;
            $all_results[] = [
                'message_id' => $msgId,
                'number' => $num,
                'status' => 'Queued',
                'network' => 'myCRMSIM'
            ];
        } else {
            error_log("[myCRMSIM] Hardware API failed for $num (Status: $status). Falling back to Semaphore.");
            // If one fails, we fall back the entire remaining set to Semaphore to ensure delivery
            $myCrmSimSuccess = false;
            break; 
        }
    }
}

if (!$useMyCrmSim || !$myCrmSimSuccess) {
    // ── Send via Semaphore (Default or Fallback) ────────────────────────────
    
    // If this is a fallback send, we MUST deduct credits now because we skipped it earlier
    if ($useMyCrmSim && !$myCrmSimSuccess) {
        try {
            if ($usingFreeCredits) {
                    // Log trial logic for fallback
                    $intRef->set([
                        'free_usage_count' => $freeUsageCount + $num_recipients,
                        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                    ], ['merge' => true]);
                    $desc = "SMS Message to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
                    $creditManager->record_trial_usage(
                        $account_id,
                        $required_credits,
                        $batch_id ?? ('trial_' . bin2hex(random_bytes(4))),
                        $desc
                    );
                } else {
                    $desc = "SMS Message to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
                    $creditManager->deduct_credits(
                        $account_id,
                        $required_credits,
                        $batch_id ?? ('single_' . bin2hex(random_bytes(4))),
                        $desc
                    );
                }
        } catch (\Exception $e) {
            error_log("[Fallback] Credit deduction failed: " . $e->getMessage());
            // Fail silently or handle as needed
        }
    }

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
    // Post the outbound message to GHL Conversations so it appears in the GHL
    // Conversation Tab. Only for direct (single-recipient) messages.
    // This NEVER blocks the SMS send — all errors are swallowed and logged.
    if (!$isBulk && $locId) {
        try {
            $ghlClient = new GhlClient($db, $locId);

            // ── Step 1: Resolve GHL Conversation ID ──────────────────────────
            $ghlConvId = null;
            $resolvedContactId = $contactId;

            // Check Firestore conversation doc first (avoids extra GHL API calls)
            $convSnap = $db->collection('conversations')->document($conversation_id)->snapshot();
            if ($convSnap->exists()) {
                $ghlConvId = $convSnap->data()['ghl_conversation_id'] ?? null;
                if (!$resolvedContactId) {
                    $resolvedContactId = $convSnap->data()['ghl_contact_id'] ?? null;
                }
            }

            // If no cached conv ID, find/create it on GHL using the contactId
            if (!$ghlConvId) {
                // If contactId wasn't passed by frontend/workflow, search GHL by phone
                if (!$resolvedContactId) {
                    $searchPhoneUrl = '/contacts/?locationId=' . urlencode($locId) . '&query=' . urlencode($validNumbers[0]);
                    $phoneSearchResp = $ghlClient->request('GET', $searchPhoneUrl, null, '2021-07-28');
                    $phoneSearchData = json_decode($phoneSearchResp['body'], true);

                    if (!empty($phoneSearchData['contacts'][0]['id'])) {
                        $resolvedContactId = $phoneSearchData['contacts'][0]['id'];
                    }
                }

                if ($resolvedContactId) {
                    $ghlConvResp = $ghlClient->request(
                        'POST',
                        '/conversations/',
                        json_encode(['locationId' => $locId, 'contactId' => $resolvedContactId]),
                        '2021-04-15'
                    );
                    $ghlConvData = json_decode($ghlConvResp['body'], true);

                    if ($ghlConvResp['status'] === 400 && str_contains($ghlConvResp['body'], 'already exists')) {
                        // Search for the existing conversation
                        $searchResp = $ghlClient->request(
                            'GET',
                            '/conversations/search?contactId=' . urlencode($resolvedContactId) . '&locationId=' . urlencode($locId),
                            null,
                            '2021-04-15'
                        );
                        $searchData = json_decode($searchResp['body'], true);
                        $ghlConvId = $searchData['conversations'][0]['id'] ?? null;
                    }
                    else {
                        $ghlConvId = $ghlConvData['conversation']['id'] ?? $ghlConvData['id'] ?? null;
                    }

                    // Cache in Firestore for future sends
                    if ($ghlConvId) {
                        $db->collection('conversations')->document($conversation_id)->set([
                            'ghl_conversation_id' => $ghlConvId,
                            'ghl_contact_id' => $resolvedContactId,
                        ], ['merge' => true]);
                    }
                }
            }

            // ── Step 2: Post message to GHL Conversation ─────────────────────
            if ($ghlConvId) {
                // Write a deduplication flag to Firestore BEFORE syncing to GHL.
                // When GHL receives this payload, it will attempt to route it to ghl_provider.php.
                // The provider will check this flag and safely skip sending a duplicate SMS via Semaphore.
                $dedupKey = md5($locId . $validNumbers[0] . $message);
                $db->collection('ghl_sync_dedup')->document($dedupKey)->set([
                    'timestamp' => time(),
                    'source' => 'send_sms_sync'
                ]);

                $msgSyncResp = $ghlClient->request(
                    'POST',
                    '/conversations/messages',
                    json_encode([
                    'type' => 'SMS',
                    'contactId' => $resolvedContactId,
                    'conversationId' => $ghlConvId,
                    'locationId' => $locId,
                    'message' => $message,
                    // Removed direction since POST /conversations/messages is implicitly outbound
                ]),
                    '2021-04-15'
                );

                // Debug log to a temp file that we can read later
                $logContent = date('Y-m-d H:i:s') . " - {$locId} - Sync Resp: " . json_encode($msgSyncResp) . "\n";
                file_put_contents(sys_get_temp_dir() . '/ghl_sync_debug.log', $logContent, FILE_APPEND);

                error_log("[GHL Sync] Posted message to GHL conv {$ghlConvId} for location {$locId}");
            }
            else {
                $logContent = date('Y-m-d H:i:s') . " - {$locId} - Skipped Sync: No conv ID resolved\n";
                file_put_contents(sys_get_temp_dir() . '/ghl_sync_debug.log', $logContent, FILE_APPEND);
                error_log("[GHL Sync] Skipped — no ghl_conversation_id resolved for {$conversation_id}");
            }
        }
        catch (\Throwable $e) {
            $logContent = date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
            file_put_contents(sys_get_temp_dir() . '/ghl_sync_debug.log', $logContent, FILE_APPEND);
            // Never block SMS delivery due to GHL sync failure
            error_log('[GHL Sync] Failed (non-fatal): ' . $e->getMessage());
        }
    }
// ── End GHL Sync ──────────────────────────────────────────────────────────
}

echo json_encode([
    "status" => $total_status == 200 ? "success" : "partial_or_failed",
    "numbers" => $validNumbers,
    "message" => $message,
    "sender" => $sender,
    "batch_id" => $batch_id,
    "credits_deducted" => $required_credits,
    "response" => $all_results,
    "debug_info" => [
        "location_id" => $locId,
        "contact_id_resolved" => $resolvedContactId ?? "not_found",
        "ghl_sync_status" => isset($msgSyncResp) ? $msgSyncResp : "skipped",
        "provider_selected" => $sender,
        "is_custom_provider" => $usingCustomSender
    ]
]);
