<?php

/**
 * fix_failed_statuses.php — Re-verify and correct historical "Failed" messages.
 *
 * This script queries Firestore for messages marked "Failed" that have a
 * message_id (meaning they were actually submitted to Semaphore). It re-checks
 * each one against the Semaphore API and corrects the status if the provider
 * actually delivered them.
 *
 * Usage: Hit this endpoint once — GET /api/webhook/fix_failed_statuses.php
 *        or pass ?location_id=XXX to scope to one sub-account.
 *        Pass ?dry_run=1 to preview without making changes.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/SenderResolver.php';

$systemApiKey = $config['SEMAPHORE_API_KEY'];
$globalApiKey = $config['SEMAPHORE_GLOBAL_API_KEY'] ?? null;
$db = get_firestore();

$dryRun = ($_GET['dry_run'] ?? '0') === '1';
$filterLocation = $_GET['location_id'] ?? null;

$results = [
    'checked'   => 0,
    'corrected' => 0,
    'still_failed' => 0,
    'not_found' => 0,
    'skipped'   => 0,
    'details'   => [],
];

try {
    // Query all Failed messages
    $query = $db->collection('sms_logs')
        ->where('status', '=', 'Failed')
        ->limit(200);

    if ($filterLocation) {
        $query = $db->collection('sms_logs')
            ->where('status', '=', 'Failed')
            ->where('location_id', '=', $filterLocation)
            ->limit(200);
    }

    $documents = $query->documents();
    $apiKeyCache = [];

    // Instantiate gateway once — avoids a Firestore config read per message.
    require_once __DIR__ . '/../services/SmsGatewayService.php';
    $gateway = new SmsGatewayService();

    foreach ($documents as $doc) {
        if (!$doc->exists()) continue;

        $data = $doc->data();
        $messageId = (string)($data['message_id'] ?? '');
        $locId = $data['location_id'] ?? '';
        $errorReason = $data['error_reason'] ?? 'none';
        $recipient = $data['number'] ?? 'unknown';

        // Skip messages with no message_id (never submitted to provider)
        if (!$messageId) {
            $results['skipped']++;
            continue;
        }

        $results['checked']++;

        // Resolve API key and provider ID using the same send-route metadata as StatusSync.
        $providerName = $data['provider'] ?? 'semaphore';
        $providerMessageId = (string)($data['provider_message_id'] ?? ($data['provider_reference_id'] ?? $messageId));
        $isSystem = !empty($data['is_system']);
        $activeApiKey = $systemApiKey;
        $resolvedStatusKeySource = 'config.SEMAPHORE_API_KEY';
        if ($locId) {
            $cacheKey = implode(':', [
                (string)$locId,
                (string)$providerName,
                $isSystem ? 'system' : 'user',
                trim((string)($data['api_key_source'] ?? '')),
                trim((string)($data['sender_source'] ?? '')),
            ]);
            if (!isset($apiKeyCache[$cacheKey])) {
                $apiKeyCache[$cacheKey] = SenderResolver::resolveStatusApiKey(
                    $db,
                    (string)$locId,
                    (string)$providerName,
                    $systemApiKey,
                    $isSystem,
                    $globalApiKey,
                    $data
                );
            }
            $activeApiKey = $apiKeyCache[$cacheKey]['api_key'];
            $resolvedStatusKeySource = $apiKeyCache[$cacheKey]['source'];
        }

        // Re-check via Gateway
        $providerInstance = $gateway->getProviderInstance($providerName);

        $rawStatus = 'error';
        try {
            $statusRes = $providerInstance->checkStatus($providerMessageId, $activeApiKey);
            $rawStatus = $statusRes['status'] ?? 'error';
        } catch (\Throwable $e) {
            error_log("[fix_failed_statuses] Gateway checkStatus failed: " . $e->getMessage());
        }

        $entry = [
            'message_id' => $messageId,
            'provider_message_id' => $providerMessageId,
            'location_id' => $locId,
            'recipient' => $recipient,
            'old_error_reason' => $errorReason,
            'provider_status' => $rawStatus,
            'provider' => $providerName,
            'api_key_source' => $resolvedStatusKeySource,
        ];

        if ($rawStatus !== 'error' && $rawStatus !== 'not_found') {
            if (in_array($rawStatus, ['sent', 'success', 'delivered'])) {
                // *** This message was actually SENT — fix it! ***
                $entry['action'] = 'CORRECTED to Sent';

                if (!$dryRun) {
                    $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());
                    $updates = [
                        ['path' => 'status', 'value' => 'Sent'],
                        ['path' => 'updated_at', 'value' => $ts],
                        ['path' => 'error_reason', 'value' => null],
                        ['path' => 'fix_note', 'value' => "Corrected from Failed to Sent by fix_failed_statuses.php. Provider raw: $rawStatus"],
                    ];
                    $doc->reference()->update($updates);

                    // Also update the messages collection
                    try {
                        $db->collection('messages')->document($messageId)->update($updates);
                    } catch (\Exception $e) {}
                }
                $results['corrected']++;
            } else {
                // Genuinely failed or still sending
                $entry['action'] = 'Confirmed status: ' . $rawStatus;
                $results['still_failed']++;
            }
        } elseif ($rawStatus === 'not_found') {
            $entry['action'] = 'Not found on provider';
            $results['not_found']++;
        } else {
            $entry['action'] = "API error or error status returned";
        }

        $results['details'][] = $entry;

        usleep(500000); // 0.5s rate limit
    }
} catch (\Exception $e) {
    $results['error'] = $e->getMessage();
}

$results['dry_run'] = $dryRun;

echo json_encode($results, JSON_PRETTY_PRINT);
