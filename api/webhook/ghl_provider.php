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

$SEMAPHORE_API_KEY      = $config['SEMAPHORE_API_KEY'];
$SEMAPHORE_URL          = $config['SEMAPHORE_URL'];
$SENDER_IDS             = $config['SENDER_IDS'];
// MASTER_APPROVED_SENDERS is now loaded dynamically from Firestore (see below after $db init)

// ── Authentication ──────────────────────────────────────────────────────────
// Requires X-Webhook-Secret to be set in the GHL Developer Portal
validate_api_request();

// ── Parse Payload ───────────────────────────────────────────────────────────
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

error_log('[ghl_provider] Received payload: ' . $raw);

$locationId = $payload['locationId'] ?? $payload['location_id'] ?? null;
if ($locationId) $locationId = trim((string)$locationId);

$contactId = $payload['contactId'] ?? $payload['contact_id'] ?? null;
$phone = $payload['phone'] ?? null;
$message = $payload['message'] ?? $payload['body'] ?? null;
$messageId = $payload['messageId'] ?? $payload['message_id'] ?? null;
$msgType = strtoupper($payload['type'] ?? 'SMS');

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
// Uses direction+content hash with a 120s window (increased from 30s to handle slow GHL echoes).
$dedupKey = md5($locationId . '_outbound_' . $normalizedPhone . $message);
$dedupRef = $db->collection('ghl_sync_dedup')->document($dedupKey);
$dedupSnap = $dedupRef->snapshot();

if ($dedupSnap->exists()) {
    $dedupData = $dedupSnap->data();
    if (time() - $dedupData['timestamp'] < 120) {
        // It's a sync loop! Acknowledge success to GHL without sending via Semaphore
        error_log('[ghl_provider] Skipped sending message to ' . $normalizedPhone . ' (prevented double-send loop, age=' . (time() - $dedupData['timestamp']) . 's).');

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

// ── Dynamic MASTER_APPROVED_SENDERS from Firestore ──────────────────────────
$masterSendersSnap = $db->collection('admin_config')->document('master_senders')->snapshot();
$MASTER_APPROVED_SENDERS = $masterSendersSnap->exists()
    ? ($masterSendersSnap->data()['approved_senders'] ?? ['NOLASMSPro'])
    : ['NOLASMSPro'];

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



// ── Sender & Gateway Resolution (mirrors send_sms.php logic) ───────────────
//
// PATH A — Subaccount has its own (external) API key:
//   → Route through their key. They own their sender registrations.
//   → NOLA credits still deducted (no bypass), but free trial is skipped.
//
// PATH B — Using the NOLA master billing gateway:
//   → Sender MUST be in MASTER_APPROVED_SENDERS or fall back to NOLASMSPro.
//   → Free trial applies first, then paid deduction.

$usingOwnApiKey = false;
$sender         = $SENDER_IDS[0] ?? 'NOLASMSPro';
$activeApiKey   = $SEMAPHORE_API_KEY;

$sysKey  = trim((string)$SEMAPHORE_API_KEY);
$userKey = trim((string)($customApiKey ?? ''));

if ($userKey !== '' && $userKey !== $sysKey) {
    // ── PATH A: External API key ─────────────────────────────────────────────
    $usingOwnApiKey = true;
    $activeApiKey   = $customApiKey;
    // Use their approved sender freely — they own their Semaphore account
    $sender = !empty($approvedSenderId) ? $approvedSenderId : ($SENDER_IDS[0] ?? 'NOLASMSPro');

} else {
    // ── PATH B: Master billing gateway ───────────────────────────────────────
    // If Admin has approved a custom sender for this subaccount, TRUST IT.
    // Otherwise, check our master whitelist.
    
    if (!empty($approvedSenderId)) {
        // Safe because it was approved by Admin in the dashboard
        $sender = $approvedSenderId;
    } elseif (!empty($desiredSender) && in_array($desiredSender, $MASTER_APPROVED_SENDERS)) {
        // Check manually requested sender against whitelist
        $sender = $desiredSender;
    } else {
        // Fallback to system default
        $sender = $SENDER_IDS[0] ?? 'NOLASMSPro';
        if (!empty($desiredSender) && $desiredSender !== $sender) {
            error_log("[ghl_provider] Requested sender '{$desiredSender}' not approved and no subaccount sender exists. Falling back to '{$sender}'.");
        }
    }
}

// ── Charging Logic ───────────────────────────────────────────────────────────
// Own-API-key users skip free trial but still consume NOLA credits.
// Free trial only applies on the master gateway (PATH B).
$usingFreeCredits = !$usingOwnApiKey && ($freeUsageCount + $required_credits <= $freeCreditsTotal);

// ── Debug: Log the billing decision path ────────────────────────────────────
error_log("[ghl_provider] BILLING DECISION for loc={$locationId}: " . json_encode([
    'usingOwnApiKey'       => $usingOwnApiKey,
    'usingFreeCredits'     => $usingFreeCredits,
    'freeUsageCount'       => $freeUsageCount,
    'freeCreditsTotal'     => $freeCreditsTotal,
    'required_credits'     => $required_credits,
    'account_id'           => $account_id,
    'sender'               => $sender,
    'customApiKey_present' => !empty($customApiKey),
    'intDocId'             => $intDocId,
    'intSnap_exists'       => $intSnap->exists(),
]));

// ── Credit Deduction & Trial Logging ─────────────────────────────────────────
if ($usingFreeCredits) {
    error_log("[ghl_provider] BILLING PATH: FreeTrial for loc={$locationId}");
    $intRef->set([
        'free_usage_count' => $freeUsageCount + $required_credits,
        'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);

    try {
        $creditManager->record_trial_usage(
            $locationId,
            $required_credits,
            $messageId ?? ('ghl_prov_trial_' . bin2hex(random_bytes(4))),
            "SMS (Trial) to {$normalizedPhone}"
        );
    } catch (\Exception $e) {
        error_log("[ghl_provider] Trial logging failed: " . $e->getMessage());
    }

} else {
    // Paid deduction — applies to ALL sends (PATH A and non-trial PATH B)
    error_log("[ghl_provider] BILLING PATH: PaidDeduction for loc={$locationId}");
    try {
        $agencyDoc = $db->collection('agency_subaccounts')->document($locationId)->snapshot();
        $agency_id = $agencyDoc->exists() ? ($agencyDoc->data()['agency_id'] ?? '') : '';
        $provider  = $usingOwnApiKey ? 'semaphore_custom' : 'semaphore';

        // ── Pre-flight: subaccount balance check ────────────────────────────
        $subBalance = $creditManager->get_balance($account_id);
        if ($subBalance <= 0) {
            http_response_code(402);
            echo json_encode([
                'success'            => false,
                'error'              => 'insufficient_credits',
                'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
                'subaccount_balance' => $subBalance,
            ]);
            exit;
        }

        $refId = $messageId ?? ('ghl_prov_' . bin2hex(random_bytes(4)));
        $desc = "SMS to {$normalizedPhone}";

        $txMetadata = [
            'message_body'    => $message,
            'chars'           => mb_strlen($message, 'UTF-8'),
            'to_number'       => $normalizedPhone,
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

        // ── Deduction: mirror send_sms.php — only dual-deduct when master lock is ON ──
        // ghl_provider was previously always calling deduct_agency_and_subaccount() whenever
        // agency_id existed, which required the agency wallet to also have balance. This caused
        // 402 errors even when the subaccount had sufficient credits.
        if ($agency_id && $creditManager->get_agency_master_lock($agency_id)) {
            $creditManager->deduct_agency_and_subaccount(
                $account_id,
                $agency_id,
                $required_credits,
                $required_credits,
                $refId,
                $desc,
                null,
                null,
                $provider,
                $txMetadata
            );
        } else {
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
                'success'            => false,
                'error'              => 'insufficient_credits',
                'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
                'subaccount_balance' => $errData['subaccount_balance'] ?? null,
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Credit deduction failed: ' . $e->getMessage()]);
        }
        exit;
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
    if (!$usingOwnApiKey) {
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

// Derive a display name — GHL provider doesn't always send contact name, fall back to phone
$displayName = $payload['contactName'] ?? $payload['name'] ?? $normalizedPhone;

$msgData = [
    'conversation_id' => $convId,
    'location_id'     => $locationId,
    'number'          => $normalizedPhone,
    'message'         => $message,
    'direction'       => 'outbound',
    'sender_id'       => $sender,
    'status'          => $semMsg['status'] ?? 'Queued',
    'batch_id'        => null,
    'ghl_message_id'  => $messageId,
    'created_at'      => $ts,
    'date_created'    => $ts,
    'segments'        => $required_credits,
    'source'          => 'ghl_provider',
    'name'            => $displayName,
];

$db->collection('messages')->document($storedMsgId)->set($msgData, ['merge' => true]);

$logData = [
    'message_id'      => $storedMsgId,
    'location_id'     => $locationId,
    'numbers'         => [$normalizedPhone],
    'message'         => $message,
    'sender_id'       => $sender,
    'status'          => $semMsg['status'] ?? 'Queued',
    'date_created'    => $ts,
    'source'          => 'ghl_provider',
    'credits_used'    => $required_credits,
    'conversation_id' => $convId,
];

$db->collection('sms_logs')->document($storedMsgId)->set($logData, ['merge' => true]);

$db->collection('conversations')->document($convId)->set([
    'id'              => $convId,
    'location_id'     => $locationId,
    'last_message'    => $message,
    'last_message_at' => $ts,
    'updated_at'      => $ts,
    'type'            => 'direct',
    'members'         => [$normalizedPhone],
    'name'            => $displayName,   // Required by web UI sidebar
    'ghl_contact_id'  => $contactId,
], ['merge' => true]);

// ── Success ─────────────────────────────────────────────────────────────────
// ── Success ─────────────────────────────────────────────────────────────────
echo json_encode([
    'success' => true,
    'status' => 'success',
    'message' => $sender,
    'execution_log' => "NOLA Provider: SMS sent to $normalizedPhone via $sender. Credits: $required_credits.",
    'action_executed_from' => 'Nola Web',
    'event_details' => [
        'Status' => 'Success',
        'Recipient' => $normalizedPhone,
        'SMS Message' => $message,
        'Credits Used' => $required_credits,
        'Sender ID' => $sender,
        'Location ID' => $locationId,
        'Timestamp' => date('Y-m-d H:i:s')
    ],
    'data' => [
        'messageId' => $storedMsgId,
        'conversation_id' => $convId,
        'number' => $normalizedPhone,
        'credits_used' => $required_credits,
        'location_id' => $locationId
    ],
    // Some GHL UI versions use these top-level keys
    'message_body' => $message,
    'sender' => $sender
]);
