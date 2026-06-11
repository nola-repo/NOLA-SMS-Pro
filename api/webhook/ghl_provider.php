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
 *
 * IMPORTANT — Early-Response Architecture:
 * GHL's custom provider webhook has a ~10-15 second timeout. Our processing
 * (billing + Semaphore API + Firestore writes) can take 15-25 seconds.
 * To prevent GHL from marking messages as "failed" due to timeout, we:
 *   1. Validate the request and check the credit balance (< 1s)
 *   2. Flush HTTP 200 back to GHL immediately (Connection: close)
 *   3. Continue billing deduction, Semaphore send, and Firestore writes
 *      in the background while Apache keeps the worker alive.
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// Keep the worker alive after GHL closes the HTTP connection.
ignore_user_abort(true);
set_time_limit(120);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require __DIR__ . '/../install_helpers.php';
require __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/SmsGatewayService.php';
$gateway = new SmsGatewayService();

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

// GHL may deliver Marketplace AppInstall/AppUninstall events to any configured provider URL.
// Handle lifecycle events here too so uninstalled locations are blocked before any SMS path.
if (install_is_marketplace_lifecycle_payload($payload)) {
    try {
        $dbMarketplace = get_firestore();
        $marketplaceResult = install_handle_marketplace_webhook($dbMarketplace, $payload, $config);
        http_response_code((int)$marketplaceResult['status']);
        echo json_encode($marketplaceResult['body']);
    } catch (Throwable $e) {
        error_log('[ghl_provider] marketplace lifecycle handler failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Marketplace webhook processing failed']);
    }
    exit;
}

error_log('[ghl_provider] Received payload: ' . $raw);
$providerReqId = 'ghlp_' . bin2hex(random_bytes(6));
error_log('[ghl_provider][INGRESS] ' . json_encode([
    'req_id' => $providerReqId,
    'locationId' => $payload['locationId'] ?? $payload['location_id'] ?? null,
    'contactId' => $payload['contactId'] ?? $payload['contact_id'] ?? null,
    'messageId' => $payload['messageId'] ?? $payload['message_id'] ?? null,
    'conversationId' => $payload['conversationId'] ?? $payload['conversation_id'] ?? null,
    'type' => $payload['type'] ?? null,
    'messageDirection' => $payload['messageDirection'] ?? null,
    'has_phone' => !empty($payload['phone']),
    'has_message' => !empty($payload['message']) || !empty($payload['body']),
]));

$locationId = $payload['locationId'] ?? $payload['location_id'] ?? null;
if ($locationId) $locationId = trim((string)$locationId);

$contactId = $payload['contactId'] ?? $payload['contact_id'] ?? null;
$phone = $payload['phone'] ?? null;
$message = $payload['message'] ?? $payload['body'] ?? null;
$messageId = $payload['messageId'] ?? $payload['message_id'] ?? null;
$msgType = strtoupper($payload['type'] ?? 'SMS');
error_log('[ghl_provider][NORMALIZED] ' . json_encode([
    'req_id' => $providerReqId,
    'locationId' => $locationId,
    'contactId' => $contactId,
    'messageId' => $messageId,
    'msgType' => $msgType,
]));

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
    error_log('[ghl_provider][REJECT] ' . json_encode([
        'req_id' => $providerReqId,
        'reason' => 'invalid_phone',
        'phone' => $phone,
    ]));
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
        error_log('[ghl_provider][DEDUP_SKIP] ' . json_encode([
            'req_id' => $providerReqId,
            'locationId' => $locationId,
            'normalizedPhone' => $normalizedPhone,
            'age_seconds' => (time() - $dedupData['timestamp']),
        ]));

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

$installGate = install_location_sms_gate($db, (string)$locationId);
if (empty($installGate['allowed'])) {
    error_log('[ghl_provider][REJECT] ' . json_encode([
        'req_id' => $providerReqId,
        'reason' => $installGate['code'] ?? 'install_blocked',
        'locationId' => $locationId,
    ]));
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => (string)($installGate['reason'] ?? 'NOLA SMS Pro is not installed for this sub-account.'),
        'code' => (string)($installGate['code'] ?? 'install_blocked'),
    ]);
    exit;
}

$toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

if (!$toggleEnabled) {
    error_log('[ghl_provider][REJECT] ' . json_encode([
        'req_id' => $providerReqId,
        'reason' => 'toggle_disabled',
        'locationId' => $locationId,
    ]));
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

// ── Pre-flight Credit Balance Check (must happen BEFORE early response) ────
// We need to know if the account has enough credits before we tell GHL "success".
// We don't deduct yet — that happens after the early response flush.
$preflightSubBalance = null;
if (!$usingFreeCredits) {
    try {
        $preflightSubBalance = $creditManager->get_balance($account_id);
        if ($preflightSubBalance <= 0) {
            http_response_code(402);
            echo json_encode([
                'success'            => false,
                'error'              => 'insufficient_credits',
                'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
                'subaccount_balance' => $preflightSubBalance,
            ]);
            exit;
        }
    } catch (\Exception $e) {
        error_log('[ghl_provider] Pre-flight balance check failed: ' . $e->getMessage());
        // Continue — let the deduction step handle this
    }
}

// ── EARLY RESPONSE TO GHL ────────────────────────────────────────────────────
// GHL's provider webhook times out at ~10-15s. Our billing + Semaphore send can
// take 15-25s. We flush HTTP 200 NOW (with Content-Length so GHL closes its side),
// then continue all slow work in the background.
$earlyResponseBody = json_encode([
    'success'              => true,
    'messageId'            => $messageId,
    'status'               => 'success',
    'message'              => "SMS sent successfully to {$normalizedPhone}",
    'execution_log'        => "NOLA Provider: SMS accepted for $normalizedPhone via $sender. Credits: $required_credits.",
    'action_executed_from' => 'Nola Web',
    'event_details'        => [
        'Status'       => 'Success',
        'Recipient'    => $normalizedPhone,
        'SMS Message'  => $message,
        'Credits Used' => $required_credits,
        'Sender ID'    => $sender,
        'Location ID'  => $locationId,
        'Timestamp'    => date('Y-m-d H:i:s'),
    ],
    'data' => [
        'messageId'   => $messageId,
        'number'      => $normalizedPhone,
        'credits_used'=> $required_credits,
        'location_id' => $locationId,
        'sender'      => $sender,
    ],
]);

http_response_code(200);
header('Connection: close');
header('Content-Length: ' . strlen($earlyResponseBody));
echo $earlyResponseBody;

// Flush all output buffers so GHL receives the response immediately
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
} else {
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
}

error_log('[ghl_provider][EARLY_RESPONSE_FLUSHED] ' . json_encode([
    'req_id'     => $providerReqId,
    'locationId' => $locationId,
    'messageId'  => $messageId,
]));

// ── BACKGROUND PROCESSING (after HTTP 200 has been flushed to GHL) ───────────

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
        // Fallback: resolve agency_id from ghl_tokens.companyId if agency_subaccounts doesn't have a record
        if (!$agency_id) {
            $agency_id = (string)($tokenData['companyId'] ?? $tokenData['company_id'] ?? '');
            if ($agency_id) {
                error_log("[ghl_provider] agency_id resolved from ghl_tokens.companyId={$agency_id} for loc={$locationId}");
            }
        }
        $activeProvider = $gateway->getProviderName();
        $baseProvider = ($activeProvider === 'unisms') ? 'unisms' : 'semaphore';
        $provider  = $usingOwnApiKey ? ($baseProvider . '_custom') : $baseProvider;

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

        // ── Low Balance Trigger Coverage (ghl_provider paid deduction check) ────
        try {
            require_once __DIR__ . '/../services/NotificationService.php';
            $newBalance = $creditManager->get_balance($account_id);
            NotificationService::checkLowBalance($db, $locationId, $newBalance);
        } catch (\Throwable $e) {
            error_log('[LowBalanceAlert][ghl_provider] ' . $e->getMessage());
        }
    } catch (\Exception $e) {
        // We already sent 200 to GHL, so we can't change the HTTP response.
        // Log the billing failure so it can be investigated / manually refunded.
        error_log('[ghl_provider][BILLING_ERROR_POST_FLUSH] Credit deduction failed for loc=' . $locationId . ' msgId=' . $messageId . ': ' . $e->getMessage());
        // Note: SMS will still be sent below. This is a billing error only.
    }
}


// ── Send SMS via Gateway ────────────────────────────────────────────────────
$chosenProvider = 'semaphore';
$gatewayResults = [];
$gatewayAccepted = false;
$smsStatus = 502;
$gateway_error = 'Unknown gateway error';

try {
    $res = $gateway->send([$normalizedPhone], $message, $sender, $usingOwnApiKey ? $activeApiKey : null);
    $chosenProvider = $res['provider'];
    $gatewayResults = $res['results'];

    $firstRes = $gatewayResults[0] ?? [];
    if (!empty($firstRes['message_id']) && $firstRes['status'] !== 'failed') {
        $gatewayAccepted = true;
        $smsStatus = 200;
        $storedMsgId = $firstRes['message_id'];
    } else {
        $gateway_error = $firstRes['error'] ?? 'Provider rejected message';
    }
} catch (\Throwable $e) {
    $gateway_error = $e->getMessage();
    error_log("[ghl_provider] Gateway send failed: " . $gateway_error);
}

$smsResponse = json_encode($gatewayResults);
error_log('[ghl_provider] Gateway response: ' . $smsResponse);
error_log('[ghl_provider][GATEWAY_RESULT] ' . json_encode([
    'req_id'          => $providerReqId,
    'http_status'     => $smsStatus,
    'gateway_accepted'=> $gatewayAccepted,
    'normalizedPhone' => $normalizedPhone,
    'locationId'      => $locationId,
    'sender'          => $sender,
]));

if (!$gatewayAccepted) {
    // Semaphore rejected the message. We already sent 200 to GHL, so we can't
    // return an error HTTP code. Instead, refund the credits and call the GHL
    // status API with 'failed' so GHL updates the message badge correctly.
    error_log('[ghl_provider][SEMAPHORE_FAILED_POST_FLUSH] Semaphore rejected msg for loc=' . $locationId . ' msgId=' . $messageId . ' status=' . $smsStatus);

    if (!$usingFreeCredits) {
        try {
            $creditManager->add_credits(
                $account_id,
                $required_credits,
                $messageId ?? 'refund_ghl_prov',
                'Refund — SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
                'refund'
            );
        } catch (\Throwable $e) {
            error_log('[ghl_provider] Credit refund failed: ' . $e->getMessage());
        }
    }

    // Signal GHL that the message failed so the badge updates correctly
    try {
        require_once __DIR__ . '/../services/GhlSyncService.php';
        $ghlSyncFail = new \Nola\Services\GhlSyncService($db, $locationId);
        $failResult  = $ghlSyncFail->syncMessageStatus($messageId, 'Failed');
        error_log('[ghl_provider][SEMAPHORE_FAIL_STATUS_SYNC] ' . json_encode([
            'req_id'     => $providerReqId,
            'messageId'  => $messageId,
            'ghl_status' => $failResult['ghl_response']['status'] ?? null,
            'ghl_body'   => substr((string)($failResult['ghl_response']['body'] ?? ''), 0, 200),
        ]));
    } catch (\Throwable $e) {
        error_log('[ghl_provider] Failed status GHL sync error: ' . $e->getMessage());
    }
    exit;
}

// ── Persist to Firestore ────────────────────────────────────────────────────
$now        = new \DateTime();
$ts         = new \Google\Cloud\Core\Timestamp($now);
$convId     = $locationId . '_conv_' . $normalizedPhone;
$storedMsgId = isset($storedMsgId) ? $storedMsgId : (string)($messageId ?? uniqid('ghl_'));

$firstRes = $gatewayResults[0] ?? [];
$rawMsgStatus = strtolower($firstRes['status'] ?? 'queued');
$initialStatus = 'Sending';
if (in_array($rawMsgStatus, ['sent', 'success', 'delivered'])) {
    $initialStatus = 'Sent';
} elseif (in_array($rawMsgStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
    $initialStatus = 'Failed';
}

// Derive a display name — GHL provider doesn't always send contact name
$displayName = $payload['contactName'] ?? $payload['name'] ?? $normalizedPhone;

$msgData = [
    'conversation_id' => $convId,
    'location_id'     => $locationId,
    'number'          => $normalizedPhone,
    'message'         => $message,
    'direction'       => 'outbound',
    'sender_id'       => $sender,
    'status'          => $initialStatus,
    'batch_id'        => null,
    'ghl_message_id'  => $messageId,
    'created_at'      => $ts,
    'date_created'    => $ts,
    'segments'        => $required_credits,
    'source'          => 'ghl_provider',
    'provider'        => $chosenProvider,
    'name'            => $displayName,
];

$db->collection('messages')->document($storedMsgId)->set($msgData, ['merge' => true]);

$logData = [
    'message_id'      => $storedMsgId,
    'location_id'     => $locationId,
    'numbers'         => [$normalizedPhone],
    'message'         => $message,
    'sender_id'       => $sender,
    'status'          => $initialStatus,
    'ghl_message_id'  => $messageId,
    'date_created'    => $ts,
    'source'          => $chosenProvider,
    'provider'        => $chosenProvider,
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
    'name'            => $displayName,
    'ghl_contact_id'  => $contactId,
], ['merge' => true]);

try {
    require_once __DIR__ . '/../cache_helper.php';
    NolaCache::deleteRegistry("conversations_registry_{$locationId}");
} catch (\Throwable $cacheEx) {
    error_log("[ghl_provider] Cache invalidation failed: " . $cacheEx->getMessage());
}

// ── Sync 'delivered' status back to GHL (background, after SMS confirmed sent) ────
try {
    require_once __DIR__ . '/../services/GhlSyncService.php';
    $ghlSync  = new \Nola\Services\GhlSyncService($db, $locationId);
    $syncResult = $ghlSync->syncMessageStatus($messageId, 'Sent');
    error_log('[ghl_provider][STATUS_SYNC] ' . json_encode([
        'req_id'     => $providerReqId,
        'messageId'  => $messageId,
        'ghl_http'   => $syncResult['ghl_response']['status'] ?? null,
        'ghl_body'   => substr((string)($syncResult['ghl_response']['body'] ?? ''), 0, 300),
        'skipped'    => $syncResult['skipped'] ?? false,
        'skip_reason'=> $syncResult['reason'] ?? null,
    ]));
} catch (\Throwable $e) {
    error_log('[ghl_provider] GHL status sync failed: ' . $e->getMessage());
}

error_log('[ghl_provider][SUCCESS] ' . json_encode([
    'req_id'            => $providerReqId,
    'locationId'        => $locationId,
    'ghl_message_id'    => $messageId,
    'stored_message_id' => $storedMsgId,
    'conversation_id'   => $convId,
]));
