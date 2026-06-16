<?php

/**
 * On-demand message status checker.
 * Called by the frontend immediately after send to get real status from Semaphore.
 * 
 * GET /api/check_message_status.php?message_ids=id1,id2&location_id=xxx
 * 
 * Returns: { "results": [{ "message_id": "...", "status": "Sent|Sending|Failed" }] }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require __DIR__ . '/webhook/config.php';
require_once __DIR__ . '/services/SenderResolver.php';

validate_api_request();

$locId = get_ghl_location_id();
if (!$locId) {
    $locId = $_GET['location_id'] ?? null;
}

$rawIds = $_GET['message_ids'] ?? '';
$messageIds = array_filter(array_map('trim', explode(',', $rawIds)));

if (empty($messageIds)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing message_ids']);
    exit;
}

$config = require __DIR__ . '/webhook/config.php';
$db = get_firestore();

$systemApiKey = $config['SEMAPHORE_API_KEY'];

$mapStatus = function ($s) {
    if (!$s) return 'Sending';
    $l = strtolower($s);
    if (in_array($l, ['queued', 'pending', 'sending'])) return 'Sending';
    if (in_array($l, ['sent', 'success', 'delivered'])) return 'Sent';
    if (in_array($l, ['failed', 'expired', 'rejected', 'undelivered'])) return 'Failed';
    return ucfirst($l);
};

$results = [];

// Instantiate gateway once — provider config is read from Firestore once
// and reused for all message IDs in this request.
require_once __DIR__ . '/services/SmsGatewayService.php';
$gateway = new SmsGatewayService();

foreach ($messageIds as $messageId) {
    $messageId = (string)$messageId;

    // 1. First check Firestore — if already resolved, return immediately
    $providerName = 'semaphore';
    $isSystem = false;
    $data = [];
    try {
        $doc = $db->collection('messages')->document($messageId)->snapshot();
        if ($doc->exists()) {
            $data = $doc->data();
            $storedStatus = $mapStatus($data['status'] ?? null);
            $providerName = $data['provider'] ?? 'semaphore';
            $isSystem = !empty($data['is_system']);

            // If already in a terminal state, return from Firestore (no API call needed)
            if (in_array($storedStatus, ['Sent', 'Failed'])) {
                $results[] = [
                    'message_id' => $messageId,
                    'status'     => $storedStatus,
                    'source'     => 'firestore',
                ];
                continue;
            }
        }
    } catch (\Exception $e) {
        // Fall through
    }

    // 2. Resolve provider and check status
    $providerInstance = $gateway->getProviderInstance($providerName);
    $resolvedKey = $locId
        ? SenderResolver::resolveStatusApiKey($db, (string)$locId, (string)$providerName, $systemApiKey, $isSystem)
        : ['api_key' => $providerName === 'semaphore' ? $systemApiKey : null, 'source' => $providerName === 'semaphore' ? 'config.SEMAPHORE_API_KEY' : 'admin_config.unisms_api_key'];
    $activeApiKey = $resolvedKey['api_key'];
    $apiKeySource = $resolvedKey['source'];
    $providerMessageId = (string)($data['provider_message_id'] ?? ($data['provider_reference_id'] ?? $messageId));

    $status = 'Sending';
    try {
        $statusRes = $providerInstance->checkStatus($providerMessageId, $activeApiKey);
        $rawStatus = $statusRes['status'] ?? 'sending';

        if (in_array($rawStatus, ['sent', 'success', 'delivered'])) {
            $status = 'Sent';
        } elseif (in_array($rawStatus, ['failed', 'expired', 'rejected', 'undelivered'])) {
            $status = 'Failed';
        }

        // Persist to Firestore if resolved
        if (in_array($status, ['Sent', 'Failed'])) {
            $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
            $updateFields = [
                ['path' => 'status',     'value' => $status],
                ['path' => 'provider_status', 'value' => $rawStatus],
                ['path' => 'updated_at', 'value' => $ts],
            ];
            try {
                $db->collection('messages')->document($messageId)->update($updateFields);
                $db->collection('sms_logs')->document($messageId)->update($updateFields);
            } catch (\Exception $e) { /* non-fatal */ }
        }
    } catch (\Throwable $e) {
        error_log("[check_message_status] Gateway check status failed: " . $e->getMessage());
    }

    $results[] = [
        'message_id' => $messageId,
        'status'     => $status,
        'source'     => $providerName,
    ];

    error_log('[check_message_status] ' . json_encode([
        'location_id' => $locId,
        'message_id' => $messageId,
        'provider_message_id' => $providerMessageId,
        'provider' => $providerName,
        'provider_status' => $rawStatus ?? null,
        'normalized_status' => $status,
        'api_key_source' => $apiKeySource,
    ]));
}

echo json_encode(['success' => true, 'results' => $results]);
