<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require __DIR__ . '/../install_helpers.php';
require __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/SenderResolver.php';
require_once __DIR__ . '/../services/MessageSyncService.php';
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
    Logger::error('Request blocked: system maintenance mode active', ['endpoint' => 'send_sms']);
    Logger::response(503, ['status' => 'error', 'message' => 'System is currently in maintenance mode.']);
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
    $redactedHeaders = [];
    foreach ($headers as $key => $value) {
        $keyString = (string)$key;
        if (preg_match('/^(authorization|x-webhook-secret|x-api-key|cookie)$/i', $keyString)) {
            $redactedHeaders[$keyString] = '[REDACTED]';
            continue;
        }
        $redactedHeaders[$keyString] = $value;
    }

    $debug = [
        "timestamp" => date('Y-m-d H:i:s'),
        "method" => $_SERVER['REQUEST_METHOD'] ?? null,
        "uri" => $_SERVER['REQUEST_URI'] ?? null,
        "headers" => $redactedHeaders,
        "raw_body" => $raw,
        "json_decoded_payload" => $payload,
        "post_data" => $_POST,
        "get_data" => $_GET
    ];
    error_log("[FULL_PAYLOAD] " . json_encode($debug));

    if (getenv('SMS_PAYLOAD_DEBUG') === '1') {
        $payloadFile = sys_get_temp_dir() . '/last_payload_debug.json';
        file_put_contents($payloadFile, json_encode($debug, JSON_PRETTY_PRINT));
    }
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

function normalize_payload_section($value): array
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

function first_non_empty_payload_value(array ...$sections)
{
    $keys = array_pop($sections);
    foreach ($keys as $key) {
        foreach ($sections as $section) {
            if (!is_array($section)) continue;
            $value = $section[$key] ?? null;
            if ($value !== null && (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) && trim((string)$value) !== '') {
                return $value;
            }
        }
    }
    return null;
}

function sanitize_firestore_doc_id(string $value): string
{
    $clean = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $value);
    return substr($clean ?: hash('sha256', $value), 0, 400);
}

function request_header_value(string $name): ?string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (!empty($_SERVER[$serverKey])) {
        return trim((string)$_SERVER[$serverKey]);
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $key => $value) {
            if (strcasecmp((string)$key, $name) === 0) {
                return trim((string)$value);
            }
        }
    }
    return null;
}

function canonical_json($value): string
{
    if (is_array($value)) {
        $isList = array_keys($value) === range(0, count($value) - 1);
        if (!$isList) {
            ksort($value);
        }
        foreach ($value as $k => $v) {
            $value[$k] = is_array($v) ? json_decode(canonical_json($v), true) : $v;
        }
    }
    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/* |-------------------------------------------------------------------------- | CREDIT CALCULATION |-------------------------------------------------------------------------- */
/** @deprecated Use CreditManager::calculateRequiredCredits() */
function calculate_credits($message, $num_recipients)
{
    return CreditManager::calculateRequiredCredits($message, $num_recipients);
}

/* |-------------------------------------------------------------------------- | DEBUG VIEW |-------------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(405);
    echo json_encode([
        "status" => "error",
        "message" => "SMS endpoint requires POST"
    ]);
    exit;
}

/* |-------------------------------------------------------------------------- | RECEIVE PAYLOAD |-------------------------------------------------------------------------- */
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

// GHL Marketplace AppInstall / AppUninstall often hit the Default webhook URL (this script).
if (install_is_marketplace_lifecycle_payload($payload)) {
    try {
        $dbMarketplace = get_firestore();
        $marketplaceResult = install_handle_marketplace_webhook($dbMarketplace, $payload, $config);
        http_response_code((int)$marketplaceResult['status']);
        echo json_encode($marketplaceResult['body']);
    } catch (Throwable $e) {
        error_log('[send_sms] marketplace lifecycle handler failed: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Marketplace webhook processing failed']);
    }
    exit;
}

log_full_payload($raw, $payload);

/* |-------------------------------------------------------------------------- | EXTRACT MESSAGE + SENDER |-------------------------------------------------------------------------- */
$customData = normalize_payload_section($payload['customData'] ?? []);
$data = normalize_payload_section($payload['data'] ?? []);
$contactData = normalize_payload_section($payload['contact'] ?? $data['contact'] ?? $customData['contact'] ?? []);

$batch_id = $customData['batch_id'] ?? $data['batch_id'] ?? $payload['batch_id'] ?? $_POST['batch_id'] ?? null;
$recipient_key = $customData['recipient_key'] ?? $data['recipient_key'] ?? $payload['recipient_key'] ?? $_POST['recipient_key'] ?? null;

// GHL Contact ID — passed by GHL Workflows as {{contact.id}} in customData.
// Used by the GHL sync block below to post the message back to GHL Conversations.
$contactId = $customData['contactId'] ?? $customData['contact_id']
    ?? $data['contactId'] ?? $data['contact_id']
    ?? $payload['contactId'] ?? $payload['contact_id'] ?? null;

$message = first_non_empty_payload_value($customData, $payload, $data, [
    'message',
    'Message',
    'text',
    'body',
    'sms_message',
    'messageText',
    'message_text'
]) ?? '';

if ($message) {
    // NOTE: Do NOT use strip_tags() here — it removes anything resembling an HTML tag,
    // e.g. "hi <3" becomes "hi " which silently truncates the user's message.
    // The message arrives as JSON (not HTML) so HTML stripping is never needed.
    $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Sanitize smart unicode punctuation to GSM-7 equivalents to prevent UCS-2 segment limits
    $message = str_replace(
        ['‘', '’', '“', '”', '–', '—', '…', '`', '´'],
        ["'", "'", '"', '"', '-', '-', '...', "'", "'"],
        $message
    );

    // Collapse runs of spaces/tabs but preserve intentional newlines (multi-line SMS)
    $message = preg_replace('/[^\S\n]+/', ' ', $message);
    $message = trim($message);
}
log_sms("MESSAGE_CLEANED", $message);

// Extract Numbers — GHL Marketplace may send as 'number' or 'phone' depending on field reference
$numberRaw = first_non_empty_payload_value($customData, $payload, $data, $contactData, [
    'number',
    'phone',
    'Phone',
    'phone_number',
    'phoneNumber',
    'mobile',
    'mobile_phone',
    'recipient',
    'to'
]);
$validNumbers = clean_numbers($numberRaw);
$num_recipients = count($validNumbers);

if ($num_recipients === 0) {
    Logger::error('No valid PH numbers in request', ['raw_number' => is_string($numberRaw) ? substr($numberRaw, 0, 40) : gettype($numberRaw)]);
    Logger::response(400, ['status' => 'error', 'message' => 'No valid Philippine mobile numbers provided.']);
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
    Logger::error('Missing location_id', ['endpoint' => 'send_sms', 'hint' => 'Pass X-GHL-Location-ID header or location_id in body']);
    Logger::response(400, ['error' => 'Missing location_id.']);
    http_response_code(400);
    echo json_encode(['error' => 'Missing location_id. Pass it via X-GHL-Location-ID header or as location_id in the request body.']);
    exit;
}

$db = get_firestore();
$jwtCtx = auth_get_optional_jwt_context($db);
auth_assert_ghl_api_location_allowed($db, $jwtCtx, (string) $locId);
$ghlTokenRegistryId = auth_resolve_ghl_token_registry_id($db, $jwtCtx, (string) $locId);

// Idempotency guard: prevents duplicate sends and duplicate credit deductions.
// The frontend may pass Idempotency-Key; otherwise derive a stable short-window key
// from the normalized send payload.
$providedIdempotencyKey = request_header_value('Idempotency-Key')
    ?: ($payload['idempotency_key'] ?? $customData['idempotency_key'] ?? null);
$requestedSenderForIdempotency = $customData['sendername'] ?? $payload['sendername'] ?? $data['sendername'] ??
    $customData['sender_name'] ?? $payload['sender_name'] ?? $data['sender_name'] ?? null;
$idempotencyMaterial = [
    'location_id' => (string)$locId,
    'numbers' => array_values($validNumbers),
    'message' => $message,
    'sender' => $requestedSenderForIdempotency,
    'batch_id' => $batch_id,
    'recipient_key' => $recipient_key,
];
$idempotencyKey = $providedIdempotencyKey
    ? sanitize_firestore_doc_id((string)$providedIdempotencyKey)
    : ('auto_' . hash('sha256', canonical_json($idempotencyMaterial)));
$requestHash = hash('sha256', canonical_json($idempotencyMaterial));
$idempotencyRef = $db->collection('idempotency_keys')->document($idempotencyKey);
$idempotencyCreated = false;
try {
    $existingIdem = $idempotencyRef->snapshot();
    if ($existingIdem->exists()) {
        $idemData = $existingIdem->data();
        $sameRequest = ($idemData['request_hash'] ?? '') === $requestHash;
        if ($sameRequest && ($idemData['status'] ?? '') === 'completed') {
            $responseBody = $idemData['response_body'] ?? null;
            http_response_code((int)($idemData['http_status'] ?? 200));
            echo json_encode(is_array($responseBody) ? $responseBody : [
                'status' => 'success',
                'message' => 'Duplicate request replayed',
                'output' => [
                    'success' => true,
                    'message_ids' => $idemData['message_ids'] ?? [],
                    'location_id' => $locId,
                ],
            ]);
            exit;
        }
        if ($sameRequest && ($idemData['status'] ?? '') === 'processing') {
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'error' => 'duplicate_request',
                'message' => 'This SMS request is already being processed.',
                'idempotency_key' => $idempotencyKey,
            ]);
            exit;
        }
        if ($sameRequest && ($idemData['status'] ?? '') === 'failed') {
            $idempotencyRef->set([
                'status' => 'processing',
                'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);
            $idempotencyCreated = true;
        }
        if (!$sameRequest) {
            http_response_code(409);
            echo json_encode([
                'status' => 'error',
                'error' => 'idempotency_key_conflict',
                'message' => 'Idempotency-Key was already used for a different SMS request.',
            ]);
            exit;
        }
    }

    if (!$idempotencyCreated) {
        $idempotencyRef->create([
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'location_id' => (string)$locId,
            'status' => 'processing',
            'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'expires_at' => new \Google\Cloud\Core\Timestamp((new \DateTime())->modify('+24 hours')),
        ]);
        $idempotencyCreated = true;
    }
} catch (\Google\Cloud\Core\Exception\ConflictException $e) {
    http_response_code(409);
    echo json_encode([
        'status' => 'error',
        'error' => 'duplicate_request',
        'message' => 'This SMS request is already being processed.',
        'idempotency_key' => $idempotencyKey,
    ]);
    exit;
} catch (\Throwable $e) {
    Logger::error('Idempotency guard failed open', [
        'location_id' => $locId,
        'idempotency_key' => $idempotencyKey,
        'error' => $e->getMessage(),
    ]);
}

$markIdempotencyFailed = function (string $error, string $message, int $httpStatus = 400) use (&$idempotencyRef, &$idempotencyKey) {
    if (!isset($idempotencyRef)) {
        return;
    }
    try {
        $idempotencyRef->set([
            'status' => 'failed',
            'http_status' => $httpStatus,
            'error' => $error,
            'response_body' => [
                'status' => 'error',
                'error' => $error,
                'message' => $message,
                'idempotency_key' => $idempotencyKey ?? null,
            ],
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);
    } catch (\Throwable $e) {
        error_log('[send_sms] Failed to mark idempotency failure: ' . $e->getMessage());
    }
};

// ── System Notification Detection ────────────────────────────────────────────
// Identifies webhooks originating from the central NOLA admin location that are
// authorized to send free system notifications (welcome, low-balance, top-up, etc.).
// Security: the bypass is granted when the triggering GHL location matches the
// central location env var, or when an explicit is_system_notification flag is
// paired with a known NOLA alert type. The latter covers central workflows that
// pass the customer/source location_id for conversation/contact attribution.
$centralLocationId    = trim((string)(getenv('NOLA_ALERT_GHL_LOCATION_ID') ?: ''));
$triggeringLocationId = trim((string)($payload['location']['id'] ?? $payload['location_id'] ?? ''));
$systemAlertTypeRaw   = $customData['nola_sms_alert_type']
    ?? $customData['alert_type']
    ?? $payload['nola_sms_alert_type']
    ?? $payload['alert_type']
    ?? $data['nola_sms_alert_type']
    ?? $data['alert_type']
    ?? null;
$systemAlertType = strtolower(trim((string)($systemAlertTypeRaw ?? '')));
$knownSystemAlertTypes = [
    'low_balance',
    'top_up_success',
    'welcome',
    'sender_id_pending',
    'sender_id_approved',
    'sender_id_rejected',
    'forgot_password_otp',
];

$isSystemNotification = false;
if ($centralLocationId !== '') {
    // Direct match: the webhook was fired from within the central location
    if ($triggeringLocationId === $centralLocationId) {
        $isSystemNotification = true;
    }

    // Explicit flag in customData — trusted only when central location is involved
    $reqSystemFlag = $customData['is_system_notification'] ?? $payload['is_system_notification'] ?? $data['is_system_notification'] ?? null;
    $flagIsTrue    = ($reqSystemFlag === true || $reqSystemFlag === 'true' || $reqSystemFlag === 1 || $reqSystemFlag === '1');
    $isKnownSystemAlert = $systemAlertType !== '' && in_array($systemAlertType, $knownSystemAlertTypes, true);
    if ($flagIsTrue && ($locId === $centralLocationId || $triggeringLocationId === $centralLocationId || $isKnownSystemAlert)) {
        $isSystemNotification = true;
    }
}

error_log('[send_sms] System notification check: isSystemNotification=' . ($isSystemNotification ? 'true' : 'false')
    . ' triggeringLoc=' . $triggeringLocationId
    . ' centralLoc=' . ($centralLocationId ?: '(not set)')
    . ' locId=' . $locId
    . ' alertType=' . ($systemAlertType ?: '(none)'));

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
    Logger::error('SMS toggle disabled for location', ['location_id' => $locId]);
    Logger::response(403, ['status' => 'error', 'message' => 'SMS sending is currently disabled.']);
    $markIdempotencyFailed('sms_disabled', 'SMS sending is currently disabled for this account. Please contact your agency.', 403);
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'SMS sending is currently disabled for this account. Please contact your agency.'
    ]);
    exit;
}

$installGate = install_location_sms_gate($db, (string)$locId);
if (empty($installGate['allowed'])) {
    $markIdempotencyFailed((string)($installGate['code'] ?? 'install_blocked'), (string)($installGate['reason'] ?? 'NOLA SMS Pro is not installed for this sub-account.'), 403);
    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'error' => (string)($installGate['code'] ?? 'install_blocked'),
        'message' => (string)($installGate['reason'] ?? 'NOLA SMS Pro is not installed for this sub-account.'),
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
    Logger::error('Rate limit reached', ['location_id' => $locId, 'rate_limit' => $rateLimit, 'attempt_count' => $attemptCount]);
    Logger::response(403, ['status' => 'error', 'error' => 'rate_limit_reached']);
    $markIdempotencyFailed('rate_limit_reached', "Agency subaccount credit limit exceeded ($rateLimit).", 403);
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
$providerPreference = $intData['provider_preference'] ?? 'system';
$unismsApiKey = $intData['unisms_api_key'] ?? null;
$unismsSenderId = $intData['unisms_sender_id'] ?? null;
// Support legacy semaphore_api_key but prefer the new nola_pro_api_key.
$customApiKey = $intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? null);
if (in_array($providerPreference, ['unisms', 'unisms_custom'], true) && !empty($unismsApiKey)) {
    $customApiKey = $unismsApiKey;
}
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

$senderResolution = SenderResolver::resolve(
    $db,
    (string)$locId,
    $config,
    $intData,
    $requestedSender ? (string)$requestedSender : null,
    $isSystemNotification,
    'send_sms'
);

$usingCustomSender = (bool)$senderResolution['using_custom_key'];
$activeApiKey      = $senderResolution['active_api_key'];
$sender            = $senderResolution['sender'];
$providerPreference = $senderResolution['provider_preference'];
$approvedProvider  = $senderResolution['approved_provider'];
$apiKeySource      = $senderResolution['api_key_source'];
$billingAgencyId   = '';
$billingMasterLock = false;
$billingReferenceId = null;

$sysKey  = trim((string)$SEMAPHORE_API_KEY);
$userKey = trim((string)($customApiKey ?? ''));

if (false && $userKey !== '' && $userKey !== $sysKey) {
    // ── PATH A: External API key ─────────────────────────────────────────────
    $usingCustomSender = true;
    $activeApiKey      = $customApiKey;

    // System notifications must always use the central NOLA SMS Pro sender,
    // even when this location has its own API key and approved_sender_id.
    if ($isSystemNotification) {
        $usingCustomSender = false;
        $activeApiKey = $SEMAPHORE_API_KEY;
        $sender = 'NOLASMSPro';
        error_log("[send_sms] Result: System notification override on external API path. Routing via master gateway with sender '{$sender}'.");
    } elseif (strcasecmp((string)$requestedSender, 'NOLASMSPro') === 0) {
        $usingCustomSender = false;
        $activeApiKey = $SEMAPHORE_API_KEY;
        $sender = 'NOLASMSPro';
        error_log("[send_sms] Result: Explicit NOLASMSPro sender request on external API path. Routing via master gateway instead of approved_sender_id.");
    } elseif (!empty($unismsSenderId) && in_array($providerPreference, ['unisms', 'unisms_custom'], true)) {
        $sender = $unismsSenderId;
    } elseif (!empty($approvedSenderId)) {
        $sender = $approvedSenderId;
    } elseif (!empty($requestedSender)) {
        $sender = $requestedSender;
    }
    // else: $sender stays as the system default 'NOLASMSPro'

} elseif (false) {
    // ── PATH B: Master billing gateway ──────────────────────────────────────
    error_log("[send_sms] Resolving Sender ID for Loc: {$locId} (requested: '{$requestedSender}')");

    if ($isSystemNotification) {
        // Force the central system sender 'NOLASMSPro' for all system alerts, welcome SMS,
        // and low-balance warnings, ignoring any custom caller overrides.
        $sender = 'NOLASMSPro';
        error_log("[send_sms] Result: System notification override. Forcing sender to '{$sender}'.");
    } elseif (!empty($approvedSenderId) && in_array($approvedSenderId, $MASTER_APPROVED_SENDERS, true)) {
        // Safe only when the sender is available on the master provider account.
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
        if (!empty($approvedSenderId) && !in_array($approvedSenderId, $MASTER_APPROVED_SENDERS, true)) {
            error_log("[send_sms] Notice: approved_sender_id '{$approvedSenderId}' is not in master sender whitelist. Falling back to '{$sender}'.");
        }
        if (!empty($requestedSender) && $requestedSender !== $sender) {
            error_log("[send_sms] Notice: Requested sender '{$requestedSender}' not approved and no subaccount sender exists.");
        }
    }
}

// ── Charging Logic (Quota vs Paid) ───────────────────────────────────────────
// Rules per handoff design:
// - Subaccounts using the NOLA master gateway: free trial applies first, then paid.
// - Subaccounts using their OWN API key (PATH A): trial applies before paid credits.
//   (They route their own SMS costs, but paid NOLA platform credits apply after trial.)
// IMPORTANT: credit count uses $required_credits (actual SMS segments), NOT $num_recipients.

// Free trial applies before paid wallet deduction, regardless of provider path.
$usingFreeCredits = ($freeUsageCount + $required_credits <= $freeCreditsTotal);

$creditManager = new CreditManager();
require_once __DIR__ . '/../services/SmsGatewayService.php';
$gateway = new SmsGatewayService();
$account_id = $locId ?: 'default';

// System notifications skip ALL credit logic (trial and paid).
$bypassBilling = $isSystemNotification;

// ── Debug: Log the billing decision path ─────────────────────────────────────
Logger::info('SMS billing decision', [
    'location_id'          => $locId,
    'num_recipients'       => $num_recipients,
    'required_credits'     => $required_credits,
    'using_own_api_key'    => $usingCustomSender,
    'using_free_credits'   => $usingFreeCredits,
    'bypass_billing'       => $bypassBilling,
    'is_system_notif'      => $isSystemNotification,
        'sender'               => $sender,
        'selected_provider'    => $providerPreference,
        'approved_provider'    => $approvedProvider,
        'api_key_source'       => $apiKeySource,
]);
error_log("[send_sms] BILLING DECISION for loc={$locId}: " . json_encode([
    'usingOwnApiKey'       => $usingCustomSender,
    'usingFreeCredits'     => $usingFreeCredits,
    'bypassBilling'        => $bypassBilling,
    'isSystemNotification' => $isSystemNotification,
    'freeUsageCount'       => $freeUsageCount,
    'freeCreditsTotal'     => $freeCreditsTotal,
    'required_credits'     => $required_credits,
    'num_recipients'       => $num_recipients,
    'account_id'           => $account_id,
        'customApiKey_present' => !empty($customApiKey),
        'provider_preference'  => $providerPreference,
        'approved_provider'    => $approvedProvider,
        'api_key_source'       => $apiKeySource,
]));

// ── Credit Deduction & Trial ──────────────────────────────────────────────────
if ($bypassBilling) {
    // Free system notification — no trial counter increment, no wallet deduction.
    error_log("[send_sms] BILLING BYPASS: System notification. Skipping credit deduction for loc={$locId}.");

} elseif ($usingFreeCredits) {
    // Free Trial (PATH B only) → increment counter, no paid credit deduction
    $intRef->set([
        'free_usage_count' => $freeUsageCount + $required_credits,
        'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ], ['merge' => true]);

    try {
        $desc = "SMS (Trial) to " . ($num_recipients === 1 ? $validNumbers[0] : "$num_recipients recipient(s)");
        $billingReferenceId = $batch_id ?? ('trial_' . bin2hex(random_bytes(4)));
        $creditManager->record_trial_usage(
            $account_id,
            $required_credits,
            $billingReferenceId,
            $desc
        );
    } catch (\Exception $e) {
        error_log("Trial logging failed: " . $e->getMessage());
    }

} else {
    // Paid deduction — applies to ALL sends (both PATH A and non-trial PATH B).
    // Own-API-key users consume paid NOLA platform credits only after trial is exhausted.

    // Resolve agency_id for logging and lock check.
    // Primary: agency_subaccounts/{locId}.agency_id
    // Fallback: companyId from the location's ghl_tokens doc (set during OAuth install)
    $agencyDoc = $db->collection('agency_subaccounts')->document($locId)->snapshot();
    $billingAgencyId = $agencyDoc->exists() ? ($agencyDoc->data()['agency_id'] ?? '') : '';
    if (!$billingAgencyId) {
        $billingAgencyId = (string)($tokenData['companyId'] ?? $tokenData['company_id'] ?? '');
        if ($billingAgencyId) {
            error_log("[send_sms] agency_id resolved from ghl_tokens.companyId={$billingAgencyId} for loc={$locId}");
        }
    }

    // ── 1. Subaccount balance pre-flight ────────────────────────────────────
    $subBalance = $creditManager->get_balance($account_id);
    if ($subBalance <= 0) {
        Logger::error('Insufficient credits — subaccount balance zero', ['location_id' => $locId, 'balance' => $subBalance]);
        Logger::response(402, ['status' => 'error', 'error' => 'insufficient_credits']);
        $markIdempotencyFailed('insufficient_credits', 'Your account has no credits. Please top up or request credits from your agency.', 402);
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
    $billingMasterLock = $billingAgencyId !== '' && $creditManager->get_agency_master_lock($billingAgencyId);
    if ($billingMasterLock) {
        $agencyBalance = $creditManager->get_agency_balance($billingAgencyId);
        if ($agencyBalance <= 0) {
            $markIdempotencyFailed('agency_master_lock', 'Sending is temporarily paused by your agency. Please contact your administrator.', 402);
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
        $billingReferenceId = $refId;

        // Tag own-API-key sends so the transaction log shows the correct provider.
        $activeProvider = in_array($providerPreference, ['unisms', 'unisms_custom'], true)
            ? 'unisms'
            : ($gateway->getProviderName() === 'unisms' ? 'unisms' : 'semaphore');
        $baseProvider = ($activeProvider === 'unisms') ? 'unisms' : 'semaphore';
        $provider = $usingCustomSender ? ($baseProvider . '_custom') : $baseProvider;

        $txMetadata = [
            'message_body'    => $message,
            'chars'           => mb_strlen($message, 'UTF-8'),
            'to_number'       => implode(', ', $validNumbers),
            'subaccount_name' => $intData['location_name'] ?? 'Unknown Subaccount',
            'agency_name'     => 'Unknown Agency'
        ];
        if ($billingAgencyId) {
            $agSnap = $db->collection('ghl_tokens')->document($billingAgencyId)->snapshot();
            if ($agSnap->exists() && !empty($agSnap->data()['agency_name'])) {
                $txMetadata['agency_name'] = $agSnap->data()['agency_name'];
            } elseif ($agSnap->exists() && !empty($agSnap->data()['company_name'])) {
                $txMetadata['agency_name'] = $agSnap->data()['company_name'];
            }
        }

        if ($billingMasterLock) {
            // Master balance lock is ON — deduct from BOTH agency and subaccount wallets.
            // Agency balance must cover the send; if empty, the send is blocked.
            $creditManager->deduct_agency_and_subaccount(
                $account_id,
                $billingAgencyId,
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
                $billingAgencyId ?: '',
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
            $markIdempotencyFailed('insufficient_credits', 'Your account has no credits. Please top up or request credits from your agency.', 402);
            http_response_code(402);
            echo json_encode([
                'status'             => 'error',
                'error'              => 'insufficient_credits',
                'message'            => 'Your account has no credits. Please top up or request credits from your agency.',
                'subaccount_balance' => $errData['subaccount_balance'] ?? null,
            ]);
        } else {
            $markIdempotencyFailed('credit_deduction_failed', 'Credit deduction failed.', 500);
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

$all_results = [];
$gateway_errors = [];
$total_status = 200;
$chosenProvider = 'semaphore';

try {
    $gatewayResults = $gateway->send(
        $validNumbers,
        $message,
        $sender,
        $usingCustomSender ? $activeApiKey : null,
        $providerPreference
    );
    $chosenProvider = $gatewayResults['provider'];
    $all_results = $gatewayResults['results'];

    foreach ($all_results as $row) {
        if (isset($row['status']) && $row['status'] === 'failed') {
            $gateway_errors[] = $row['error'] ?? 'Provider send failed';
            $total_status = 502;
        }
    }

    Logger::info('Gateway send completed', [
        'provider'       => $chosenProvider,
        'location_id'    => $locId,
        'sender_name'    => $sender,
        'api_key_source' => $apiKeySource,
        'num_recipients' => $num_recipients,
        'total_status'   => $total_status,
        'failed_count'   => count($gateway_errors),
    ]);
} catch (\Throwable $e) {
    Logger::error('Gateway send threw exception', [
        'provider'    => $gateway->getProviderName(),
        'location_id' => $locId,
        'exception'   => $e->getMessage(),
    ]);
    $gateway_errors[] = $e->getMessage();
    $total_status = 502;
    
    // Create dummy failed results so Firestore logging still executes and the UI shows 'Failed' instead of disappearing
    $all_results = array_map(function($num) use ($e) {
        return [
            'message_id' => 'failed_' . bin2hex(random_bytes(4)),
            'status' => 'failed',
            'recipient' => $num,
            'error' => $e->getMessage()
        ];
    }, $validNumbers);
}


/* |-------------------------------------------------------------------------- | SAVE FIRESTORE |-------------------------------------------------------------------------- */
$saved_message_ids = [];
$message_results = array_values(array_filter($all_results, function ($msg) {
    return is_array($msg) && !empty($msg['message_id']);
}));

if (!empty($message_results)) {
    $db = get_firestore();
    $now = new \DateTime();
    $ts = new \Google\Cloud\Core\Timestamp($now);

    // A send is "bulk" (uses a shared group conversation) when:
    //   - Multiple numbers are in this single request (GHL Marketplace style), OR
    //   - A batch_id is present — the frontend always sets batch_id for bulk sends,
    //     even though it calls this endpoint one phone at a time.
    $isBulk = count($validNumbers) > 1 || !empty($batch_id);
    $prefix = $locId . '_';

    $conversation_id = $isBulk
        ? ($prefix . 'group_' . ($batch_id ?? 'bulk'))
        : ($prefix . 'conv_' . $validNumbers[0]);

    // Calculate credits per message for logging (0 if this is a free system notification)
    $credits_per_message = $bypassBilling ? 0 : CreditManager::calculateRequiredCredits($message, 1);

    foreach ($message_results as $msg) {
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

        MessageSyncService::recordMessageEvent($db, [
            'origin' => 'send_sms',
            'conversation_id' => $conversation_id,
            'conversation_type' => $isBulk ? 'group' : 'direct',
            'conversation_members' => [$recipient],
            'append_members' => $isBulk,
            'location_id' => $locId,
            'number' => $recipient,
            'message' => $message,
            'direction' => 'outbound',
            'sender_id' => $sender_id,
            'sender_name' => $sender_id,
            'status' => $initialStatus,
            'batch_id' => $batch_id,
            'recipient_key' => $recipientKey,
            'created_at' => $ts,
            'date_created' => $ts,
            'timestamp' => $ts,
            'name' => $recipientName,
            'conversation_name' => !$isBulk ? ($recipientName ?: $recipient) : null,
            'message_id' => $messageId,
            'provider_reference_id' => $msg['provider_reference_id'] ?? $messageId,
            'provider_message_id' => $msg['provider_message_id'] ?? ($msg['provider_reference_id'] ?? $messageId),
            'segments' => $credits_per_message,
            'credits_used' => $credits_per_message,
            'provider' => $chosenProvider,
            'provider_status' => $msg['status'] ?? null,
            'provider_response' => $msg['provider_response'] ?? null,
            'provider_error' => $msg['error'] ?? null,
            'idempotency_key' => $idempotencyKey ?? null,
            'is_system' => $isSystemNotification,
        ]);

        if (false) {
        $saveData = [
            'conversation_id' => $conversation_id,
            'location_id' => $locId,
            'number' => $recipient,
            'message' => $message,
            'direction' => 'outbound',
            'sender_id' => $sender_id,
            'sender_name' => $sender_id,
            'status' => $initialStatus,
            'batch_id' => $batch_id,
            'recipient_key' => $recipientKey,
            'created_at' => $ts,
            'date_created' => $ts,
            'name' => $recipientName,
            'message_id' => $messageId,
            'provider_reference_id' => $msg['provider_reference_id'] ?? $messageId,
            'provider_message_id' => $msg['provider_message_id'] ?? ($msg['provider_reference_id'] ?? $messageId),
            'segments' => $credits_per_message,
            'provider' => $chosenProvider,
            'provider_status' => $msg['status'] ?? null,
            'provider_response' => $msg['provider_response'] ?? null,
            'provider_error' => $msg['error'] ?? null,
            'idempotency_key' => $idempotencyKey ?? null,
            'is_system' => $isSystemNotification
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
            'sender_name' => $sender,
            'status' => $initialStatus,
            'date_created' => $ts,
            'source' => $chosenProvider,
            'provider' => $chosenProvider,
            'batch_id' => $batch_id,
            'recipient_key' => $recipient_key ?? $recipient,
            'credits_used' => $credits_per_message,
            'conversation_id' => $conversation_id,
            'provider_reference_id' => $msg['provider_reference_id'] ?? $messageId,
            'provider_message_id' => $msg['provider_message_id'] ?? ($msg['provider_reference_id'] ?? $messageId),
            'provider_status' => $msg['status'] ?? null,
            'provider_error' => $msg['error'] ?? null,
            'provider_response' => $msg['provider_response'] ?? null,
            'idempotency_key' => $idempotencyKey ?? null,
            'is_system' => $isSystemNotification
        ];

        if ($locId) {
            $logData['location_id'] = $locId;
        }

        $db->collection('sms_logs')
            ->document($messageId)
            ->set($logData, ['merge' => true]);
        }
    }

    // For bulk sends the frontend calls this endpoint once per recipient (sequential),
    // all sharing the same batch_id. Using arrayUnion in a set+merge means each call
    // atomically appends its recipient to the members list — works whether the doc
    // already exists or is being created for the very first time.
    // For direct (single) sends, we simply set the members array normally.
    if (false && $isBulk) {
        $db->collection('conversations')
            ->document($conversation_id)
            ->set([
                'id'              => $conversation_id,
                'location_id'     => $locId,
                'last_message'    => $message,
                'last_message_at' => $ts,
                'updated_at'      => $ts,
                'type'            => 'group',
                // arrayUnion creates the field if missing, appends if it exists — never duplicates
                'members'         => \Google\Cloud\Firestore\FieldValue::arrayUnion($validNumbers),
            ], ['merge' => true]);
    } elseif (false) {
        $db->collection('conversations')
            ->document($conversation_id)
            ->set([
                'id'              => $conversation_id,
                'location_id'     => $locId,
                'last_message'    => $message,
                'last_message_at' => $ts,
                'updated_at'      => $ts,
                'members'         => $validNumbers,
                'name'            => $recipientName ?: $validNumbers[0],
                'type'            => 'direct',
            ], ['merge' => true]);
    }

    if (false) try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::deleteRegistry("conversations_registry_{$locId}");
    } catch (\Throwable $cacheEx) {
        error_log("[send_sms] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    // ── GHL Bidirectional Sync (Best-Effort) ─────────────────────────────────
    // GHL bidirectional sync: run for every individual-number send (including bulk
    // recipients), since each message should appear in its GHL contact's conversation.
    if (count($validNumbers) === 1 && $locId && !empty($messageId)) {
        try {
            $ghlSync = new \Nola\Services\GhlSyncService($db, $locId, $ghlTokenRegistryId);
            $syncRes = $ghlSync->syncOutboundMessage($validNumbers[0], $message, $contactId);
            if (!empty($syncRes['ghl_message_id'])) {
                $ghlMessageId = $syncRes['ghl_message_id'];
                
                // Update Firestore messages and sms_logs with GHL Message ID
                $db->collection('messages')->document($messageId)->update([
                    ['path' => 'ghl_message_id', 'value' => $ghlMessageId]
                ]);
                $db->collection('sms_logs')->document($messageId)->update([
                    ['path' => 'ghl_message_id', 'value' => $ghlMessageId]
                ]);
            }
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
                $ghlClient = new GhlClient($db, $locId, $ghlTokenRegistryId);
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
$failedResultCount = 0;
foreach (($message_results ?? []) as $msg) {
    $rawStatus = strtolower((string)($msg['status'] ?? ''));
    if (in_array($rawStatus, ['failed', 'expired', 'rejected', 'undelivered'], true)) {
        $failedResultCount++;
    }
}

$hasSavedMessages = !empty($saved_message_ids);
$allSavedMessagesFailed = $hasSavedMessages && $failedResultCount >= count($saved_message_ids);
$gatewayAccepted = (
    $total_status >= 200 &&
    $total_status < 300 &&
    $hasSavedMessages &&
    empty($gateway_errors) &&
    !$allSavedMessagesFailed
);

if (!$gatewayAccepted && !$bypassBilling) {
    if ($usingFreeCredits) {
        try {
            $intRef->set([
                'free_usage_count' => \Google\Cloud\Firestore\FieldValue::increment(-$required_credits),
                'updated_at'       => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            ], ['merge' => true]);

            if ($billingReferenceId !== null) {
                $trialTxDocs = $db->collection('credit_transactions')
                    ->where('account_id', '=', CreditManager::integration_doc_id_for_location($account_id))
                    ->where('reference_id', '=', $billingReferenceId)
                    ->documents();
                foreach ($trialTxDocs as $trialTxDoc) {
                    if ($trialTxDoc->exists()) {
                        $trialTxDoc->reference()->delete();
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[send_sms] Trial rollback failed after gateway rejection: ' . $e->getMessage());
        }
    } else {
        try {
            $refundRef = 'refund_' . ($billingReferenceId ?? bin2hex(random_bytes(4)));
            $creditManager->add_credits(
                $account_id,
                $required_credits,
                $refundRef,
                'Refund - SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
                'refund'
            );

            if ($billingMasterLock && $billingAgencyId !== '') {
                $creditManager->add_credits(
                    $billingAgencyId,
                    $required_credits,
                    $refundRef . '_agency',
                    'Agency refund - SMS failed to send (' . ucfirst($chosenProvider) . ' rejected)',
                    'refund',
                    'agency'
                );
            }
        } catch (\Throwable $e) {
            error_log('[send_sms] Credit refund failed after gateway rejection: ' . $e->getMessage());
        }
    }
}

if (!$gatewayAccepted) {
    http_response_code($total_status >= 400 ? $total_status : 502);
}

$ghlStatus = $gatewayAccepted ? "success" : "error";
$summary = "Sent to " . implode(', ', $validNumbers);
if (count($validNumbers) > 3) {
    $summary = "Sent to " . count($validNumbers) . " recipients";
}

// GHL Legacy/Success response structure
$reportedCredits = $bypassBilling ? 0 : $required_credits;

Logger::response(
    $gatewayAccepted ? 200 : ($total_status >= 400 ? $total_status : 502),
    [
        'success'        => $gatewayAccepted,
        'status'         => $ghlStatus,
        'location_id'    => $locId,
        'num_recipients' => $num_recipients,
        'credits_used'   => $reportedCredits,
        'provider'       => $chosenProvider,
        'sender'         => $sender,
        'bypass_billing' => $bypassBilling,
        'is_system_notif'=> $isSystemNotification,
    ]
);

$responsePayload = [
    "status"               => $ghlStatus,
    "message"              => $sender,
    "execution_log"        => "Workflow SMS sent via $sender to $summary. Credits: {$reportedCredits}.",
    "action_executed_from" => "Nola Web",
    "event_details"        => [
        "Status"       => $gatewayAccepted ? "Success" : "Failed",
        "Recipient(s)" => implode(', ', $validNumbers),
        "SMS Message"  => $message,
        "Credits Used" => $reportedCredits,
        "Sender ID"    => $sender,
        "Location ID"  => $locId,
        "Timestamp"    => date('Y-m-d H:i:s')
    ],
    "output" => [
        "success"      => $gatewayAccepted,
        "summary"      => $summary,
        "credits"      => $reportedCredits,
        "location_id"  => $locId,
        "message_ids"  => $saved_message_ids ?? []
    ],
    "debug_info" => [
        "location_id"           => $locId,
        "ghl_sync_status"       => isset($msgSyncResp) ? $msgSyncResp : "skipped",
        "is_custom_provider"    => $usingCustomSender,
        "is_free_trial"         => $usingFreeCredits,
        "is_system_notification"=> $isSystemNotification,
        "bypass_billing"        => $bypassBilling,
        "used_credits"          => $reportedCredits,
        "gateway_errors"        => $gateway_errors
    ]
];

if (isset($idempotencyRef)) {
    try {
        $idempotencyRef->set([
            'status' => $gatewayAccepted ? 'completed' : 'failed',
            'http_status' => $gatewayAccepted ? 200 : ($total_status >= 400 ? $total_status : 502),
            'message_ids' => $saved_message_ids ?? [],
            'response_body' => $responsePayload,
            'provider' => $chosenProvider,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);
    } catch (\Throwable $e) {
        error_log('[send_sms] Failed to update idempotency record: ' . $e->getMessage());
    }
}

echo json_encode($responsePayload);
