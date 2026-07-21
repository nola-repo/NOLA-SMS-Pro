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
require_once __DIR__ . '/../services/SenderResolver.php';
require_once __DIR__ . '/../services/MessageSyncService.php';
require_once __DIR__ . '/../services/GhlClient.php';
require_once __DIR__ . '/../services/SmsGatewayService.php';
require_once __DIR__ . '/../services/FirestoreId.php';
require_once __DIR__ . '/../services/ProviderResultService.php';
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

function sanitize_local_sms_doc_id(string $value): string
{
    return FirestoreId::sanitize($value);
}

function build_local_sms_doc_id(string $locationId, string $provider, string $providerMessageId, string $recipient, ?string $ghlMessageId = null): string
{
    return FirestoreId::smsLogId($locationId, $provider, $providerMessageId, $recipient, $ghlMessageId);
}

function provider_record_blocked_message($db, string $locationId, ?string $phone, string $message, string $reason, ?string $contactId = null, ?string $ghlMessageId = null, array $context = []): ?string
{
    if (!$db || trim($locationId) === '') {
        return null;
    }

    $digits = preg_replace('/\D/', '', (string)($phone ?? ''));
    $member = $digits !== '' ? $digits : 'unknown';
    $conversationId = $digits !== '' ? ($locationId . '_conv_' . $member) : ($locationId . '_workflow_blocked');
    $messageId = 'ghlp_block_' . substr(hash('sha256', json_encode([
        $locationId,
        $phone,
        $message,
        $reason,
        microtime(true),
        bin2hex(random_bytes(4)),
    ])), 0, 32);
    $now = new \Google\Cloud\Core\Timestamp(new \DateTime());

    try {
        MessageSyncService::recordMessageEvent($db, [
            'origin' => 'ghl_provider_blocked',
            'conversation_id' => $conversationId,
            'conversation_type' => 'direct',
            'conversation_members' => $digits !== '' ? [$member] : [],
            'location_id' => $locationId,
            'number' => $member,
            'message' => $message !== '' ? $message : ('GHL SMS blocked: ' . $reason),
            'direction' => 'outbound',
            'status' => 'Failed',
            'created_at' => $now,
            'date_created' => $now,
            'timestamp' => $now,
            'source' => 'ghl_provider',
            'provider' => 'nola_internal',
            'provider_status' => 'blocked',
            'provider_error' => $reason,
            'ghl_message_id' => $ghlMessageId,
            'ghl_contact_id' => $contactId,
            'message_id' => $messageId,
        ]);

        $extra = array_filter([
            'workflow_blocked' => true,
            'workflow_block_reason' => $reason,
            'workflow_block_context' => $context,
            'updated_at' => $now,
        ], static fn($v) => $v !== null);
        $db->collection('messages')->document($messageId)->set($extra, ['merge' => true]);
        $db->collection('sms_logs')->document($messageId)->set($extra, ['merge' => true]);
        return $messageId;
    } catch (\Throwable $e) {
        error_log('[ghl_provider] Failed to record blocked provider message: ' . $e->getMessage());
        return null;
    }
}

function provider_payload_section($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function provider_nested_value(array $payload, array $path)
{
    $current = $payload;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }
    return $current;
}

function provider_first_scalar(array $payload, array $paths): ?string
{
    foreach ($paths as $path) {
        $value = is_array($path) ? provider_nested_value($payload, $path) : ($payload[$path] ?? null);
        if ($value !== null && !is_array($value) && !is_object($value) && trim((string)$value) !== '') {
            $value = trim((string)$value);
            if (strpos($value, '{{') === false) {
                return $value;
            }
        }
    }
    return null;
}

function provider_flush_json_response(array $body, int $statusCode = 200): void
{
    if (headers_sent()) {
        return;
    }

    $responseBody = json_encode($body);
    http_response_code($statusCode);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($responseBody));
    echo $responseBody;

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}

$payload = is_array($payload) ? $payload : [];
$payload['data'] = provider_payload_section($payload['data'] ?? []);
$payload['customData'] = provider_payload_section($payload['customData'] ?? []);
$payload['contact'] = provider_payload_section($payload['contact'] ?? ($payload['data']['contact'] ?? []));
$payload['location'] = provider_payload_section($payload['location'] ?? ($payload['data']['location'] ?? []));
$payload['workflow'] = provider_payload_section($payload['workflow'] ?? ($payload['data']['workflow'] ?? []));
$messageNode = provider_payload_section($payload['message'] ?? $payload['messageData'] ?? ($payload['data']['message'] ?? []));

$locationId = provider_first_scalar($payload, [
    'locationId',
    'location_id',
    ['location', 'id'],
    ['location', 'locationId'],
    ['data', 'locationId'],
    ['data', 'location_id'],
    ['customData', 'locationId'],
    ['customData', 'location_id'],
    ['workflow', 'locationId'],
    ['workflow', 'location_id'],
]);
if ($locationId) $locationId = trim((string)$locationId);

$contactId = provider_first_scalar($payload, [
    'contactId',
    'contact_id',
    ['contact', 'id'],
    ['contact', 'contactId'],
    ['data', 'contactId'],
    ['data', 'contact_id'],
    ['customData', 'contactId'],
    ['customData', 'contact_id'],
    ['workflow', 'contactId'],
]);
$phone = provider_first_scalar($payload, [
    'phone',
    'to',
    'number',
    'recipient',
    ['contact', 'phone'],
    ['contact', 'phoneNumber'],
    ['contact', 'mobile'],
    ['data', 'phone'],
    ['data', 'to'],
    ['customData', 'phone'],
    ['customData', 'number'],
    ['message', 'to'],
]);
$message = provider_first_scalar($payload, [
    'message',
    'body',
    'text',
    'content',
    'messageText',
    'message_text',
    ['data', 'message'],
    ['data', 'body'],
    ['customData', 'message'],
    ['customData', 'body'],
    ['message', 'message'],
    ['message', 'body'],
    ['message', 'text'],
]);
if (!$message && !empty($messageNode)) {
    $message = provider_first_scalar(['message' => $messageNode], [
        ['message', 'message'],
        ['message', 'body'],
        ['message', 'text'],
        ['message', 'content'],
    ]);
}
$messageId = provider_first_scalar($payload, [
    'messageId',
    'message_id',
    'id',
    ['message', 'messageId'],
    ['message', 'message_id'],
    ['message', 'id'],
    ['data', 'messageId'],
    ['data', 'message_id'],
]);
$msgType = strtoupper(provider_first_scalar($payload, [
    'type',
    'messageType',
    'message_type',
    ['message', 'type'],
    ['data', 'type'],
]) ?? 'SMS');
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
if (!$phone && $locationId && $contactId) {
    if (!isset($db)) {
        $db = get_firestore();
    }

    try {
        $contactClient = new \GhlClient($db, (string)$locationId);
        $contactResp = $contactClient->request('GET', '/contacts/' . urlencode((string)$contactId));
        if (($contactResp['status'] ?? 500) < 300) {
            $contactPayload = json_decode((string)($contactResp['body'] ?? ''), true);
            $contactNode = is_array($contactPayload) ? ($contactPayload['contact'] ?? $contactPayload) : [];
            if (is_array($contactNode)) {
                $phone = provider_first_scalar(['contact' => $contactNode], [
                    ['contact', 'phone'],
                    ['contact', 'phoneNumber'],
                    ['contact', 'mobile'],
                    ['contact', 'additionalPhones', 0],
                ]);
                if (!empty($contactNode)) {
                    $payload['contact'] = array_merge($payload['contact'] ?? [], $contactNode);
                }
            }
        } else {
            error_log('[ghl_provider][CONTACT_PHONE_LOOKUP_FAILED] ' . json_encode([
                'req_id' => $providerReqId,
                'locationId' => $locationId,
                'contactId' => $contactId,
                'status' => $contactResp['status'] ?? null,
                'body' => substr((string)($contactResp['body'] ?? ''), 0, 200),
            ]));
        }
    } catch (\Throwable $e) {
        error_log('[ghl_provider][CONTACT_PHONE_LOOKUP_ERROR] ' . json_encode([
            'req_id' => $providerReqId,
            'locationId' => $locationId,
            'contactId' => $contactId,
            'error' => $e->getMessage(),
        ]));
    }
}

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
    if (!isset($db)) {
        $db = get_firestore();
    }
    $blockedMessageId = provider_record_blocked_message($db, (string)$locationId, (string)$phone, (string)$message, 'invalid_phone', $contactId ? (string)$contactId : null, $messageId ? (string)$messageId : null, [
        'req_id' => $providerReqId,
        'raw_phone' => $phone,
        'digits' => $digits,
        'expected' => 'Philippine mobile number beginning 09 or +639',
    ]);
    error_log('[ghl_provider][REJECT] ' . json_encode([
        'req_id' => $providerReqId,
        'reason' => 'invalid_phone',
        'phone' => $phone,
        'local_message_id' => $blockedMessageId,
    ]));
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'invalid_phone',
        'message' => "Invalid Philippine mobile number: {$phone}. Use a mobile number beginning 09 or +639.",
        'messageId' => $messageId,
        'local_message_id' => $blockedMessageId,
    ]);
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
$providerPreference = $intData['provider_preference'] ?? 'system';
$unismsApiKey = $intData['unisms_api_key'] ?? null;
$unismsSenderId = $intData['unisms_sender_id'] ?? null;
$customApiKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
if (in_array($providerPreference, ['unisms', 'unisms_custom'], true) && !empty($unismsApiKey)) {
    $customApiKey = $unismsApiKey;
}
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
//   → Trial credits apply first; paid NOLA platform credits apply after trial.
//
// PATH B — Using the NOLA master billing gateway:
//   → Sender MUST be in MASTER_APPROVED_SENDERS or fall back to NOLASMSPro.
//   → Free trial applies first, then paid deduction.

$senderResolution = SenderResolver::resolve(
    $db,
    (string)$locationId,
    $config,
    $intData,
    null,
    false,
    'ghl_provider'
);

$usingOwnApiKey = (bool)$senderResolution['using_custom_key'];
$sender         = $senderResolution['sender'];
$activeApiKey   = $senderResolution['active_api_key'];
$providerPreference = $senderResolution['provider_preference'];
$approvedProvider = $senderResolution['approved_provider'];
$apiKeySource = $senderResolution['api_key_source'];
$billingAgencyId = '';
$billingMasterLock = false;

if ($providerValidation = ProviderResultService::providerMessageValidation($providerPreference, (string)$message)) {
    $validationMessage = $providerValidation['message'];
    error_log('[ghl_provider][REJECT] ' . json_encode([
        'req_id' => $providerReqId,
        'reason' => $providerValidation['error'],
        'locationId' => $locationId,
        'chars' => $providerValidation['characters'],
    ]));
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'status' => 'error',
    ] + $providerValidation);
    exit;
}

$sysKey  = trim((string)$SEMAPHORE_API_KEY);
$userKey = trim((string)($customApiKey ?? ''));

if (false && $userKey !== '' && $userKey !== $sysKey) {
    // ── PATH A: External API key ─────────────────────────────────────────────
    $usingOwnApiKey = true;
    $activeApiKey   = $customApiKey;
    // Use their approved sender freely — they own their Semaphore account
    $sender = (!empty($unismsSenderId) && in_array($providerPreference, ['unisms', 'unisms_custom'], true))
        ? $unismsSenderId
        : (!empty($approvedSenderId) ? $approvedSenderId : ($SENDER_IDS[0] ?? 'NOLASMSPro'));

} elseif (false) {
    // ── PATH B: Master billing gateway ───────────────────────────────────────
    // If Admin has approved a custom sender for this subaccount, TRUST IT.
    // Otherwise, check our master whitelist.
    
    if (!empty($approvedSenderId) && in_array($approvedSenderId, $MASTER_APPROVED_SENDERS, true)) {
        // Safe only when the sender is available on the master provider account.
        $sender = $approvedSenderId;
    } elseif (!empty($desiredSender) && in_array($desiredSender, $MASTER_APPROVED_SENDERS)) {
        // Check manually requested sender against whitelist
        $sender = $desiredSender;
    } else {
        // Fallback to system default
        $sender = $SENDER_IDS[0] ?? 'NOLASMSPro';
        if (!empty($approvedSenderId) && !in_array($approvedSenderId, $MASTER_APPROVED_SENDERS, true)) {
            error_log("[ghl_provider] approved_sender_id '{$approvedSenderId}' is not in master sender whitelist. Falling back to '{$sender}'.");
        }
        if (!empty($desiredSender) && $desiredSender !== $sender) {
            error_log("[ghl_provider] Requested sender '{$desiredSender}' not approved and no subaccount sender exists. Falling back to '{$sender}'.");
        }
    }
}

// ── Charging Logic ───────────────────────────────────────────────────────────
// Trial credits apply before paid wallet deduction, regardless of provider path.
$usingFreeCredits = ($freeUsageCount + $required_credits <= $freeCreditsTotal);

// ── Debug: Log the billing decision path ────────────────────────────────────
error_log("[ghl_provider] BILLING DECISION for loc={$locationId}: " . json_encode([
    'usingOwnApiKey'       => $usingOwnApiKey,
    'usingFreeCredits'     => $usingFreeCredits,
    'freeUsageCount'       => $freeUsageCount,
    'freeCreditsTotal'     => $freeCreditsTotal,
    'required_credits'     => $required_credits,
    'account_id'           => $account_id,
    'sender'               => $sender,
    'selected_provider'    => $providerPreference,
    'approved_provider'    => $approvedProvider,
    'api_key_source'       => $apiKeySource,
    'customApiKey_present' => !empty($customApiKey),
    'provider_preference'  => $providerPreference,
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
// GHL's provider webhook times out at ~15s. Our billing + gateway send can take
// longer. We flush HTTP 200 NOW so GHL marks the step as executed, then continue
// all slow work in the background.
$convId = $locationId . '_conv_' . $normalizedPhone;
$displayName = provider_first_scalar($payload, [
    'contactName',
    'name',
    ['contact', 'name'],
    ['contact', 'fullName'],
    ['contact', 'firstName'],
    ['data', 'contactName'],
    ['customData', 'contactName'],
]) ?? $normalizedPhone;
$localProviderMessageId = build_local_sms_doc_id(
    (string)$locationId,
    'ghl_provider',
    (string)($messageId ?: $providerReqId),
    (string)$normalizedPhone,
    $messageId ? (string)$messageId : null
);
$providerPreflightTs = new \Google\Cloud\Core\Timestamp(new \DateTime());

try {
    MessageSyncService::recordMessageEvent($db, [
        'origin' => 'ghl_provider_preflight',
        'conversation_id' => $convId,
        'conversation_type' => 'direct',
        'conversation_members' => [$normalizedPhone],
        'location_id' => $locationId,
        'number' => $normalizedPhone,
        'message' => $message,
        'direction' => 'outbound',
        'sender_id' => $sender,
        'sender_name' => $sender,
        'status' => 'Sending',
        'ghl_message_id' => $messageId,
        'ghl_contact_id' => $contactId,
        'created_at' => $providerPreflightTs,
        'date_created' => $providerPreflightTs,
        'timestamp' => $providerPreflightTs,
        'segments' => $required_credits,
        'credits_used' => $required_credits,
        'source' => 'ghl_provider',
        'type' => 'Conversation Provider',
        'provider' => 'ghl_provider',
        'provider_reference_id' => $messageId ?: $localProviderMessageId,
        'provider_message_id' => $messageId ?: $localProviderMessageId,
        'provider_status' => 'accepted',
        'name' => $displayName,
        'conversation_name' => $displayName,
        'message_id' => $localProviderMessageId,
    ]);
} catch (\Throwable $e) {
    error_log('[ghl_provider][PREFLIGHT_LOCAL_WRITE_FAILED] ' . json_encode([
        'req_id' => $providerReqId,
        'locationId' => $locationId,
        'messageId' => $messageId,
        'local_message_id' => $localProviderMessageId,
        'error' => $e->getMessage(),
    ]));
}

$earlyResponseBody = json_encode([
    'success'              => true,
    'messageId'            => $messageId,
    'status'               => 'success',
    'message'              => "SMS queued for {$normalizedPhone}",
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
        'messageId'    => $messageId,
        'number'       => $normalizedPhone,
        'credits_used' => $required_credits,
        'location_id'  => $locationId,
        'sender'       => $sender,
    ],
]);

if (!headers_sent()) {
    http_response_code(200);
    header('Content-Type: application/json');
    header('Connection: close');
    header('Content-Length: ' . strlen($earlyResponseBody));
    echo $earlyResponseBody;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level() > 0) { ob_end_flush(); }
        flush();
    }
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
        $billingAgencyId = $agency_id;
        $billingMasterLock = $agency_id !== '' && $creditManager->get_agency_master_lock($agency_id);
        $activeProvider = in_array($providerPreference, ['unisms', 'unisms_custom'], true)
            ? 'unisms'
            : $gateway->getProviderName();
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
        if ($billingMasterLock) {
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
    $res = $gateway->send([$normalizedPhone], $message, $sender, $usingOwnApiKey ? $activeApiKey : null, $providerPreference);
    $chosenProvider = $res['provider'];
    $gatewayResults = $res['results'];

    $gatewaySummary = ProviderResultService::summarizeGatewayResults($gatewayResults);
    $firstRes = $gatewayResults[0] ?? [];
    if (!empty($firstRes['message_id']) && !ProviderResultService::isFailedStatus($firstRes['status'] ?? null)) {
        $gatewayAccepted = true;
        $smsStatus = 200;
        $storedMsgId = $firstRes['message_id'];
    } else {
        $gateway_error = $firstRes['error'] ?? ProviderResultService::failureMessage($gatewaySummary['errors'], $chosenProvider);
        $smsStatus = $gatewaySummary['http_status'];
    }
} catch (\Throwable $e) {
    $gatewaySummary = ProviderResultService::summarizeGatewayException($e);
    $smsStatus = $gatewaySummary['http_status'];
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
    'selected_provider' => $chosenProvider,
    'api_key_source' => $apiKeySource,
]));

if (!$gatewayAccepted) {
    // GHL already got HTTP 200 (early flush above). We cannot change the HTTP
    // status, but we refund credits and log the failure for investigation.
    error_log('[ghl_provider][PROVIDER_FAILED_POST_FLUSH] ' . ucfirst($chosenProvider) . ' rejected msg for loc=' . $locationId . ' msgId=' . $messageId . ' status=' . $smsStatus);

    if ($usingFreeCredits) {
        try {
            $intRef->set([
                'free_usage_count' => \Google\Cloud\Firestore\FieldValue::increment(-$required_credits),
                'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);

            if (!empty($messageId)) {
                $trialTxDocs = $db->collection('credit_transactions')
                    ->where('account_id', '=', CreditManager::integration_doc_id_for_location($locationId))
                    ->where('reference_id', '=', $messageId)
                    ->documents();
                foreach ($trialTxDocs as $trialTxDoc) {
                    if ($trialTxDoc->exists()) {
                        $trialTxDoc->reference()->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[ghl_provider] Trial rollback failed: ' . $e->getMessage());
        }
    } else {
        try {
            $creditManager->add_credits(
                $account_id,
                $required_credits,
                $messageId ?? 'refund_ghl_prov',
                'Refund — SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
                'refund'
            );
            if ($billingMasterLock && $billingAgencyId !== '') {
                $creditManager->add_credits(
                    $billingAgencyId,
                    $required_credits,
                    ($messageId ?? 'refund_ghl_prov') . '_agency',
                    'Agency refund - SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
                    'refund',
                    'agency'
                );
            }
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
    $failureReason = $gateway_error ?: ProviderResultService::failureMessage($gatewaySummary['errors'] ?? [], $chosenProvider);
    error_log('[ghl_provider][GATEWAY_FAILURE_POST_FLUSH] ' . json_encode([
        'req_id'     => $providerReqId,
        'locationId' => $locationId,
        'messageId'  => $messageId,
        'status'     => $smsStatus,
        'reason'     => $failureReason,
    ]));
    try {
        MessageSyncService::recordMessageEvent($db, [
            'origin' => 'ghl_provider_failed',
            'conversation_id' => $convId,
            'conversation_type' => 'direct',
            'conversation_members' => [$normalizedPhone],
            'location_id' => $locationId,
            'number' => $normalizedPhone,
            'message' => $message,
            'direction' => 'outbound',
            'sender_id' => $sender,
            'sender_name' => $sender,
            'status' => 'Failed',
            'ghl_message_id' => $messageId,
            'ghl_contact_id' => $contactId,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'segments' => $required_credits,
            'credits_used' => $required_credits,
            'source' => 'ghl_provider',
            'provider' => $chosenProvider,
            'provider_status' => $firstRes['status'] ?? null,
            'provider_response' => $firstRes['provider_response'] ?? null,
            'provider_error' => $failureReason,
            'name' => $displayName,
            'conversation_name' => $displayName,
            'message_id' => $localProviderMessageId,
        ]);
    } catch (\Throwable $e) {
        error_log('[ghl_provider][FAILED_LOCAL_UPDATE_ERROR] ' . $e->getMessage());
    }
    exit;
}

error_log('[ghl_provider][GATEWAY_ACCEPTED_POST_FLUSH] ' . json_encode([
    'req_id' => $providerReqId,
    'locationId' => $locationId,
    'messageId' => $messageId,
    'provider' => $chosenProvider,
]));

// ── Persist to Firestore ────────────────────────────────────────────────────
$now        = new \DateTime();
$ts         = new \Google\Cloud\Core\Timestamp($now);
$firstRes = $gatewayResults[0] ?? [];
$providerRawMessageId = isset($storedMsgId) ? (string)$storedMsgId : (string)($messageId ?? uniqid('ghl_'));
$providerReferenceId = $firstRes['provider_reference_id'] ?? $providerRawMessageId;
$providerMessageId = $firstRes['provider_message_id'] ?? $providerReferenceId;
$storedMsgId = $localProviderMessageId;
$rawMsgStatus = strtolower($firstRes['status'] ?? 'queued');
$initialStatus = 'Sending';
if (in_array($rawMsgStatus, ['sent', 'success', 'delivered'])) {
    $initialStatus = 'Sent';
} elseif (in_array($rawMsgStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
    $initialStatus = 'Failed';
}

// Derive a display name — GHL provider doesn't always send contact name
$displayName = provider_first_scalar($payload, [
    'contactName',
    'name',
    ['contact', 'name'],
    ['contact', 'fullName'],
    ['contact', 'firstName'],
    ['data', 'contactName'],
    ['customData', 'contactName'],
]) ?? $normalizedPhone;

MessageSyncService::recordMessageEvent($db, [
    'origin' => 'ghl_provider',
    'conversation_id' => $convId,
    'conversation_type' => 'direct',
    'conversation_members' => [$normalizedPhone],
    'location_id' => $locationId,
    'number' => $normalizedPhone,
    'message' => $message,
    'direction' => 'outbound',
    'sender_id' => $sender,
    'sender_name' => $sender,
    'status' => $initialStatus,
    'ghl_message_id' => $messageId,
    'ghl_contact_id' => $contactId,
    'created_at' => $ts,
    'date_created' => $ts,
    'timestamp' => $ts,
    'segments' => $required_credits,
    'credits_used' => $required_credits,
    'source' => 'ghl_provider',
    'provider' => $chosenProvider,
    'provider_reference_id' => $providerReferenceId,
    'provider_message_id' => $providerMessageId,
    'provider_status' => $firstRes['status'] ?? null,
    'provider_response' => $firstRes['provider_response'] ?? null,
    'provider_error' => $firstRes['error'] ?? null,
    'name' => $displayName,
    'conversation_name' => $displayName,
    'message_id' => $storedMsgId,
]);

if (false) {
$msgData = [
    'conversation_id' => $convId,
    'location_id'     => $locationId,
    'number'          => $normalizedPhone,
    'message'         => $message,
    'direction'       => 'outbound',
    'sender_id'       => $sender,
    'sender_name'     => $sender,
    'status'          => $initialStatus,
    'batch_id'        => null,
    'ghl_message_id'  => $messageId,
    'created_at'      => $ts,
    'date_created'    => $ts,
    'segments'        => $required_credits,
    'source'          => 'ghl_provider',
    'provider'        => $chosenProvider,
    'provider_reference_id' => $firstRes['provider_reference_id'] ?? $storedMsgId,
    'provider_message_id' => $firstRes['provider_message_id'] ?? ($firstRes['provider_reference_id'] ?? $storedMsgId),
    'provider_status' => $firstRes['status'] ?? null,
    'provider_response' => $firstRes['provider_response'] ?? null,
    'provider_error'  => $firstRes['error'] ?? null,
    'name'            => $displayName,
];

$db->collection('messages')->document($storedMsgId)->set($msgData, ['merge' => true]);

$logData = [
    'message_id'      => $storedMsgId,
    'location_id'     => $locationId,
    'numbers'         => [$normalizedPhone],
    'message'         => $message,
    'sender_id'       => $sender,
    'sender_name'     => $sender,
    'status'          => $initialStatus,
    'ghl_message_id'  => $messageId,
    'date_created'    => $ts,
    'source'          => $chosenProvider,
    'provider'        => $chosenProvider,
    'provider_reference_id' => $firstRes['provider_reference_id'] ?? $storedMsgId,
    'provider_message_id' => $firstRes['provider_message_id'] ?? ($firstRes['provider_reference_id'] ?? $storedMsgId),
    'provider_status' => $firstRes['status'] ?? null,
    'provider_response' => $firstRes['provider_response'] ?? null,
    'provider_error'  => $firstRes['error'] ?? null,
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
}

// Sync only terminal provider statuses back to GHL.
// Gateway acceptance can still be queued/pending; the status cron promotes
// those messages after the carrier/provider confirms final delivery state.
if ($initialStatus === 'Sent') {
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
} else {
    error_log('[ghl_provider][STATUS_SYNC_DEFERRED] ' . json_encode([
        'req_id' => $providerReqId,
        'messageId' => $messageId,
        'provider_status' => $firstRes['status'] ?? null,
        'initial_status' => $initialStatus,
    ]));
}

error_log('[ghl_provider][SUCCESS] ' . json_encode([
    'req_id'            => $providerReqId,
    'locationId'        => $locationId,
    'ghl_message_id'    => $messageId,
    'stored_message_id' => $storedMsgId,
    'conversation_id'   => $convId,
]));
