<?php

/**
 * ghl_provider.php — GHL Conversation Provider Outbound Message Webhook.
 *
 * GHL calls this endpoint when a user sends a message via the NOLA SMS Pro
 * provider in the GHL Conversation View (i.e., the interactive chat box).
 *
 * GHL Payload for Custom Provider - SMS:
 * {
 *   "type": "SMS",
 *   "locationId": "...",
 *   "contactId": "...",
 *   "messageId": "...",
 *   "phone": "+639XXXXXXXXX",   // Recipient's phone (GHL provides this directly)
 *   "message": "...",           // The message text
 *   "attachments": [],
 *   "userId": "..."
 * }
 *
 * Authentication: Uses X-Webhook-Secret header (must be set in the GHL
 * Developer Portal under Conversation Providers → Custom Headers).
 * If GHL does not support custom headers, falls back to token-based auth
 * using the location's stored GHL tokens.
 */

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
$SEMAPHORE_URL     = $config['SEMAPHORE_URL'];
$SENDER_IDS        = $config['SENDER_IDS'];

// ── Authentication ──────────────────────────────────────────────────────────
// GHL Conversation Provider may NOT send X-Webhook-Secret.
// We do a soft check: if the header IS present it must be valid.
// If it is absent, we fall through to validate via locationId + stored tokens.
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if (!$receivedSecret) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $key => $value) {
        if (strcasecmp($key, 'X-Webhook-Secret') === 0) {
            $receivedSecret = $value;
            break;
        }
    }
}

$expectedSecret = getenv('WEBHOOK_SECRET') ?: 'f7RkQ2pL9zV3tX8cB1nS4yW6';
$secretValid = $receivedSecret && hash_equals($expectedSecret, $receivedSecret);

// ── Parse Payload ───────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

error_log('[ghl_provider] Received payload: ' . $raw);

$locationId = $payload['locationId'] ?? $payload['location_id'] ?? null;
$contactId = $payload['contactId'] ?? $payload['contact_id'] ?? null;
$phone = $payload['phone'] ?? null;
$message = $payload['message'] ?? $payload['body'] ?? null;
$messageId = $payload['messageId'] ?? $payload['message_id'] ?? null;
$msgType = strtoupper($payload['type'] ?? 'SMS');

// ── Token-based auth fallback ────────────────────────────────────────────────
// If no secret was provided, verify the request is legitimate by checking
// that we have a stored GHL token for this locationId.
if (!$secretValid) {
    if (!$locationId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized — no valid secret or location']);
        exit;
    }

    $db = get_firestore();
    $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    if (!$tokenSnap->exists()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized — location not registered']);
        exit;
    }
}

// ── Validate Required Fields ────────────────────────────────────────────────
if (!$locationId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing locationId']);
    exit;
}

if (!$message) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message content']);
    exit;
}

// Only handle SMS type for now
if ($msgType !== 'SMS') {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => "Message type '{$msgType}' is not supported by this provider — skipped."]);
    exit;
}

// ── Normalize Phone Number ───────────────────────────────────────────────────
if (!$phone) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing phone number']);
    exit;
}

$digits = preg_replace('/\D/', '', $phone);
if (str_starts_with($digits, '63') && strlen($digits) === 12) {
    $normalizedPhone = '0' . substr($digits, 2);
}
elseif (str_starts_with($digits, '09') && strlen($digits) === 11) {
    $normalizedPhone = $digits;
}
elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
    $normalizedPhone = '0' . $digits;
}
else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Invalid Philippine mobile number: {$phone}"]);
    exit;
}

if (!isset($db)) {
    $db = get_firestore();
}

// ── Deduplication Check ─────────────────────────────────────────────────────
// If this webhook was triggered because send_sms.php synced a message 
// back to GHL's Conversation API, we must skip sending to prevent a double-send loop.
$dedupKey = md5($locationId . $normalizedPhone . $message);
$dedupRef = $db->collection('ghl_sync_dedup')->document($dedupKey);
$dedupSnap = $dedupRef->snapshot();

if ($dedupSnap->exists()) {
    $dedupData = $dedupSnap->data();
    if (time() - $dedupData['timestamp'] < 30) {
        // It's a sync loop! Acknowledge success to GHL without sending via Semaphore
        error_log('[ghl_provider] Skipped sending message to ' . $normalizedPhone . ' (prevented double-send loop).');

        // Clean up the dedup flag to keep the database tidy
        $dedupRef->delete();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => 'Skipped to prevent double-send',
            'messageId' => $messageId
        ]);
        exit;
    }
}

// ── Check Agency Toggle ─────────────────────────────────────────────────────
if (!isset($db)) {
    $db = get_firestore();
}
$tokenRef  = $db->collection('ghl_tokens')->document($locationId);
$tokenSnap = $tokenRef->snapshot();
$tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

if (!$toggleEnabled) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error'   => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
}

// ── Load Integration Config ─────────────────────────────────────────────────
if (!isset($db)) {
    $db = get_firestore();
}

$intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
$intRef = $db->collection('integrations')->document($intDocId);
$intSnap = $intRef->snapshot();
$intData = $intSnap->exists() ? $intSnap->data() : [];

$approvedSenderId = $intData['approved_sender_id'] ?? null;
$customApiKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
$freeUsageCount = $intData['free_usage_count'] ?? 0;
$freeCreditsTotal = $intData['free_credits_total'] ?? 10;

// ── Normalize account_id for CreditManager ──────────────────────────────────
$account_id = $locationId ?: 'default';

// ── Calculate required credits (always 1 recipient for provider sends) ────────
$required_credits = CreditManager::calculateRequiredCredits($message, 1);

// ── Instantiate CreditManager ─────────────────────────────────────────────────
$creditManager = new CreditManager();



// ── Three-Tier Sender + Credit Logic ────────────────────────────────────────
$usingCustomSender = false;
$usingFreeCredits = false;

if ($approvedSenderId && $customApiKey) {
    // ✅ Tier 1: Custom sender (approved sender ID + custom API key) — no system credit deduction
    $sender = $approvedSenderId;
    $activeApiKey = $customApiKey;
    $usingCustomSender = true;
} else {
    // ✅ Tier 2 or 3: Use system sender + system API key
    $sender = $SENDER_IDS[0] ?? 'NOLASMSPro';
    $activeApiKey = $SEMAPHORE_API_KEY;
    $usingCustomSender = false;

    // Check free trial quota (always 1 recipient for provider sends)
    if ($freeUsageCount + 1 <= $freeCreditsTotal) {
        $usingFreeCredits = true; // Tier 2: still within free trial
    }
}

// ── Credit Deduction & Trial Logging ────────────────────────────────────────
// --- Only deduct/track if NOT using a Custom Sender ---
if (!$usingCustomSender) {
    if ($usingFreeCredits) {
        // Tier 2: Free Trial -> Increment free usage counter, do NOT deduct paid balance
        $intRef->set([
            'free_usage_count' => $freeUsageCount + $required_credits,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        // LOGGING: Record trial usage in transaction history for visibility (amount 0)
        try {
            $desc = "SMS Message to {$normalizedPhone}";
            $creditManager->record_trial_usage(
                $locationId,
                $required_credits,
                $messageId ?? ('ghl_prov_trial_' . bin2hex(random_bytes(4))),
                $desc
            );
        } catch (\Exception $e) {
            error_log("[ghl_provider] Trial logging failed: " . $e->getMessage());
        }
    } else {
        // Tier 3: Paid Usage -> Deduct actual paid credits
        try {
            $desc = "SMS Message to {$normalizedPhone}";
            $creditManager->deduct_credits(
                $account_id,
                $required_credits,
                $messageId ?? ('ghl_prov_' . bin2hex(random_bytes(4))),
                $desc
            );
        } catch (\Exception $e) {
            if ($e->getMessage() === 'Insufficient credits.') {
                http_response_code(402);
                echo json_encode([
                    'success' => false,
                    'error' => 'insufficient_credits',
                    'message' => 'Insufficient credits. Please top up your NOLA SMS Pro credits.',
                ]);
            } else {
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}


    // ── Send SMS via Semaphore (System Default) ────────────────────────
    $smsData = [
        'apikey' => $activeApiKey,
        'number' => $normalizedPhone,
        'message' => $message,
        'sendername' => $sender,
    ];

    $ch = curl_init($SEMAPHORE_URL);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($smsData));

    $smsResponse = curl_exec($ch);
    $smsStatus = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $smsResult = json_decode($smsResponse, true);
    error_log('[ghl_provider] Semaphore response: ' . $smsResponse);


if ($smsStatus !== 200 || empty($smsResult)) {
    // Refund credits if SMS failed and we deducted
    if (!$usingCustomSender) {
        try {
            $creditManager->add_credits(
                $account_id,
                $required_credits,
                $messageId ?? 'refund_ghl_prov',
                'Refund — SMS failed to send',
                'refund'
            );
        }
        catch (\Throwable $e) {
            error_log('[ghl_provider] Credit refund failed: ' . $e->getMessage());
        }
    }

    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'SMS gateway error', 'sms_status' => $smsStatus]);
    exit;
}

// ── Persist to Firestore ────────────────────────────────────────────────────
$now = new \DateTime();
$ts = new \Google\Cloud\Core\Timestamp($now);
$convId = $locationId . '_conv_' . $normalizedPhone;
$semMsg = is_array($smsResult) ? ($smsResult[0] ?? $smsResult) : [];
$storedMsgId = (string)($semMsg['message_id'] ?? $messageId ?? uniqid('ghl_'));

$msgData = [
    'conversation_id' => $convId,
    'location_id' => $locationId,
    'number' => $normalizedPhone,
    'message' => $message,
    'direction' => 'outbound',
    'sender_id' => $sender,
    'status' => $semMsg['status'] ?? 'Queued',
    'batch_id' => null,
    'ghl_message_id' => $messageId,
    'created_at' => $ts,
    'date_created' => $ts,
    'segments' => $required_credits,
    'source' => 'ghl_provider',
];

$db->collection('messages')->document($storedMsgId)->set($msgData, ['merge' => true]);

$logData = [
    'message_id' => $storedMsgId,
    'location_id' => $locationId,
    'numbers' => [$normalizedPhone],
    'message' => $message,
    'sender_id' => $sender,
    'status' => $semMsg['status'] ?? 'Queued',
    'date_created' => $ts,
    'source' => 'ghl_provider',
    'credits_used' => $required_credits,
    'conversation_id' => $convId,
];

$db->collection('sms_logs')->document($storedMsgId)->set($logData, ['merge' => true]);

$db->collection('conversations')->document($convId)->set([
    'id' => $convId,
    'location_id' => $locationId,
    'last_message' => $message,
    'last_message_at' => $ts,
    'updated_at' => $ts,
    'type' => 'direct',
    'members' => [$normalizedPhone],
    'ghl_contact_id' => $contactId,
], ['merge' => true]);

// ── Success ─────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'message' => 'SMS sent successfully',
    'messageId' => $storedMsgId,
    'conversation_id' => $convId,
    'number' => $normalizedPhone,
    'credits_used' => $required_credits,
]);
