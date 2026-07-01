<?php

require_once __DIR__ . '/../cors.php';
$config = require __DIR__ . '/config.php';
require __DIR__ . '/firestore_client.php';
require_once __DIR__ . '/../services/GhlSyncService.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/MessageSyncService.php';

header('Content-Type: application/json');

function unisms_json(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function unisms_header_value(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = $_SERVER[$serverKey] ?? '';
    if ($value !== '') {
        return (string)$value;
    }

    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $key => $headerValue) {
        if (strcasecmp((string)$key, $name) === 0) {
            return (string)$headerValue;
        }
    }

    return '';
}

function unisms_webhook_secret_status(array $config): array
{
    $receivedSecrets = array_values(array_filter(array_unique(array_map('trim', [
        unisms_header_value('X-Webhook-Secret'),
        (string)($_GET['secret'] ?? ''),
        (string)($_GET['token'] ?? ''),
    ]))));

    $expectedSecrets = array_values(array_filter(array_unique([
        trim((string)($config['UNISMS_WEBHOOK_SECRET'] ?? '')),
        trim((string)(getenv('UNISMS_WEBHOOK_SECRET') ?: '')),
        trim((string)(getenv('WEBHOOK_SECRET') ?: '')),
    ])));

    if (empty($expectedSecrets)) {
        error_log('[receive_sms_unisms] Server misconfiguration: no webhook secret configured.');
        return ['ok' => false, 'configured' => false, 'secret_present' => !empty($receivedSecrets)];
    }

    foreach ($expectedSecrets as $expectedSecret) {
        foreach ($receivedSecrets as $receivedSecret) {
            if ($receivedSecret !== '' && hash_equals($expectedSecret, $receivedSecret)) {
                return ['ok' => true, 'configured' => true, 'secret_present' => true];
            }
        }
    }

    return ['ok' => false, 'configured' => true, 'secret_present' => !empty($receivedSecrets)];
}

function unisms_clean_number($number): string
{
    $digits = preg_replace('/\D/', '', (string)$number);
    if (str_starts_with($digits, '639') && strlen($digits) === 12) {
        return '0' . substr($digits, 2);
    }
    if (str_starts_with($digits, '09') && strlen($digits) === 11) {
        return $digits;
    }
    if (str_starts_with($digits, '9') && strlen($digits) === 10) {
        return '0' . $digits;
    }
    return $digits;
}

function unisms_status_to_local(?string $status, ?string $event): string
{
    $raw = strtolower(trim((string)($status ?: $event)));
    if (str_contains($raw, 'delivered') || str_contains($raw, 'sent') || str_contains($raw, 'success')) {
        return 'Sent';
    }
    if (str_contains($raw, 'fail') || str_contains($raw, 'reject') || str_contains($raw, 'undelivered') || str_contains($raw, 'expired')) {
        return 'Failed';
    }
    return 'Sending';
}

function unisms_event_is_status_callback(string $event): bool
{
    $event = strtolower(trim($event));
    return str_starts_with($event, 'message.')
        && !in_array($event, ['message.received', 'message.inbound', 'message.reply'], true);
}

function unisms_reference_id(array $data, array $messageObj): string
{
    return trim((string)(
        $messageObj['reference_id']
        ?? $messageObj['id']
        ?? $data['id']
        ?? $data['reference_id']
        ?? ''
    ));
}

function unisms_payload_log_context(array $data, array $messageObj, string $event): array
{
    $senderRaw = $messageObj['sender'] ?? $messageObj['from'] ?? $messageObj['number'] ?? $data['sender'] ?? '';
    $senderNumber = unisms_clean_number($senderRaw);

    return [
        'event' => $event,
        'id' => $data['id'] ?? null,
        'reference_id' => unisms_reference_id($data, $messageObj),
        'provider_status' => $messageObj['status'] ?? $data['status'] ?? null,
        'sender_hash' => $senderNumber !== '' ? hash('sha256', $senderNumber) : null,
        'message_present' => trim((string)($messageObj['content'] ?? $messageObj['message'] ?? $data['message'] ?? '')) !== '',
    ];
}

function unisms_existing_record_matches(array $record, string $referenceId): bool
{
    $provider = strtolower(trim((string)($record['provider'] ?? '')));
    $source = strtolower(trim((string)($record['source'] ?? '')));
    $providerReferenceId = trim((string)($record['provider_reference_id'] ?? ''));
    $providerMessageId = trim((string)($record['provider_message_id'] ?? ''));
    $messageId = trim((string)($record['message_id'] ?? ''));

    return ($provider === 'unisms' || $source === 'unisms')
        && ($providerReferenceId === $referenceId || $providerMessageId === $referenceId || $messageId === $referenceId);
}

function unisms_update_outbound_status($db, array $data, array $messageObj, string $event, bool $requireExistingUnismsRecord = false): array
{
    $referenceId = unisms_reference_id($data, $messageObj);
    $providerStatus = (string)($messageObj['status'] ?? $data['status'] ?? $event);
    $localStatus = unisms_status_to_local($providerStatus, $event);
    $now = new \Google\Cloud\Core\Timestamp(new \DateTime());
    $updated = [];
    $ghlSyncTargets = [];
    $updatedPaths = [];

    if ($referenceId === '') {
        return ['updated' => [], 'status' => $localStatus, 'reference_id' => null];
    }

    $updatePayload = [
        ['path' => 'status', 'value' => $localStatus],
        ['path' => 'provider_status', 'value' => $providerStatus],
        ['path' => 'provider', 'value' => 'unisms'],
        ['path' => 'provider_reference_id', 'value' => $referenceId],
        ['path' => 'provider_message_id', 'value' => $referenceId],
        ['path' => 'provider_response', 'value' => $data],
        ['path' => 'updated_at', 'value' => $now],
    ];

    foreach (['sms_logs', 'messages'] as $collection) {
        try {
            $docRef = $db->collection($collection)->document($referenceId);
            $snap = $docRef->snapshot();
            if ($snap->exists()) {
                if ($requireExistingUnismsRecord && !unisms_existing_record_matches($snap->data(), $referenceId)) {
                    continue;
                }
                $existingData = $snap->data();
                $docRef->update($updatePayload);
                $path = "{$collection}/{$referenceId}";
                $updatedPaths[$path] = true;
                $updated[] = $path;
                if (in_array($localStatus, ['Sent', 'Failed'], true) && !empty($existingData['location_id']) && !empty($existingData['ghl_message_id'])) {
                    $ghlSyncTargets[$existingData['location_id'] . ':' . $existingData['ghl_message_id']] = [
                        'location_id' => $existingData['location_id'],
                        'ghl_message_id' => $existingData['ghl_message_id'],
                    ];
                }
                continue;
            }
        } catch (\Throwable $e) {
            error_log("[receive_sms_unisms] {$collection} direct update failed for {$referenceId}: " . $e->getMessage());
        }

        foreach (['provider_reference_id', 'provider_message_id'] as $lookupField) {
            try {
                $query = $db->collection($collection)
                    ->where($lookupField, '=', $referenceId)
                    ->limit(5)
                    ->documents();
                foreach ($query as $doc) {
                    if (!$doc->exists()) {
                        continue;
                    }
                    $path = "{$collection}/" . $doc->id();
                    if (isset($updatedPaths[$path])) {
                        continue;
                    }
                    if ($requireExistingUnismsRecord && !unisms_existing_record_matches($doc->data(), $referenceId)) {
                        continue;
                    }
                    $existingData = $doc->data();
                    $doc->reference()->update($updatePayload);
                    $updatedPaths[$path] = true;
                    $updated[] = $path;
                    if (in_array($localStatus, ['Sent', 'Failed'], true) && !empty($existingData['location_id']) && !empty($existingData['ghl_message_id'])) {
                        $ghlSyncTargets[$existingData['location_id'] . ':' . $existingData['ghl_message_id']] = [
                            'location_id' => $existingData['location_id'],
                            'ghl_message_id' => $existingData['ghl_message_id'],
                        ];
                    }
                }
            } catch (\Throwable $e) {
                error_log("[receive_sms_unisms] {$collection} {$lookupField} update failed for {$referenceId}: " . $e->getMessage());
            }
        }
    }

    $ghlResults = [];
    foreach ($ghlSyncTargets as $target) {
        try {
            $ghlSync = new \Nola\Services\GhlSyncService($db, $target['location_id']);
            $syncResult = $ghlSync->syncMessageStatus($target['ghl_message_id'], $localStatus);
            $ghlResults[] = [
                'location_id' => $target['location_id'],
                'ghl_message_id' => $target['ghl_message_id'],
                'status' => $localStatus,
                'ghl_status' => $syncResult['ghl_response']['status'] ?? null,
                'skipped' => $syncResult['skipped'] ?? false,
            ];
        } catch (\Throwable $e) {
            error_log('[receive_sms_unisms] GHL status sync failed: ' . json_encode([
                'reference_id' => $referenceId,
                'location_id' => $target['location_id'],
                'ghl_message_id' => $target['ghl_message_id'],
                'normalized_status' => $localStatus,
                'error' => $e->getMessage(),
            ]));
        }
    }

    error_log('[receive_sms_unisms] status callback processed: ' . json_encode([
        'reference_id' => $referenceId,
        'provider_status' => $providerStatus,
        'normalized_status' => $localStatus,
        'updated' => $updated,
        'ghl_sync_results' => $ghlResults,
    ]));

    return ['updated' => array_values(array_unique($updated)), 'status' => $localStatus, 'reference_id' => $referenceId, 'ghl_sync_results' => $ghlResults];
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    error_log('[receive_sms_unisms] Invalid JSON payload: ' . substr((string)$raw, 0, 500));
    unisms_json(400, ['status' => 'error', 'message' => 'Invalid JSON payload']);
}

$event = (string)($data['event'] ?? '');
$messageObj = is_array($data['message'] ?? null) ? $data['message'] : [];
$db = get_firestore();
$auth = unisms_webhook_secret_status($config);

error_log('[receive_sms_unisms] Webhook received: ' . json_encode(unisms_payload_log_context($data, $messageObj, $event)));

if (!$auth['ok']) {
    if (unisms_event_is_status_callback($event)) {
        $result = unisms_update_outbound_status($db, $data, $messageObj, $event, true);
        if (!empty($result['updated'])) {
            error_log('[receive_sms_unisms] Status callback accepted by existing UniSMS record. reference_id=' . (string)$result['reference_id']);
            unisms_json(200, [
                'status' => 'success',
                'message' => 'Webhook processed',
                'event' => $event,
                'provider' => 'unisms',
                'reference_id' => $result['reference_id'],
                'local_status' => $result['status'],
                'updated' => $result['updated'],
                'ghl_sync_results' => $result['ghl_sync_results'] ?? [],
                'auth' => 'matched_existing_unisms_record',
            ]);
        }

        error_log('[receive_sms_unisms] Status callback ignored: no matching UniSMS record. secret_present=' . ($auth['secret_present'] ? 'yes' : 'no') . ' event=' . $event . ' reference_id=' . unisms_reference_id($data, $messageObj));
        unisms_json(200, [
            'status' => 'success',
            'message' => 'Webhook ignored',
            'event' => $event,
            'provider' => 'unisms',
            'reference_id' => unisms_reference_id($data, $messageObj),
            'reason' => 'unmatched_status_callback',
            'auth' => 'none',
        ]);
    }

    if (!$auth['configured']) {
        unisms_json(500, ['status' => 'error', 'message' => 'Server misconfiguration: webhook secret missing']);
    }
    error_log('[receive_sms_unisms] Unauthorized webhook. secret_present=' . ($auth['secret_present'] ? 'yes' : 'no') . ' event=' . $event . ' reference_id=' . unisms_reference_id($data, $messageObj));
    unisms_json(401, ['status' => 'error', 'message' => 'Unauthorized Access']);
}

if (unisms_event_is_status_callback($event)) {
    $result = unisms_update_outbound_status($db, $data, $messageObj, $event, false);
    unisms_json(200, [
        'status' => 'success',
        'message' => 'Webhook processed',
        'event' => $event,
        'provider' => 'unisms',
        'reference_id' => $result['reference_id'],
        'local_status' => $result['status'],
        'updated' => $result['updated'],
        'ghl_sync_results' => $result['ghl_sync_results'] ?? [],
        'auth' => 'secret',
    ]);
}
$senderRaw = $messageObj['sender'] ?? $messageObj['from'] ?? $messageObj['number'] ?? $data['sender'] ?? '';
$message = $messageObj['content'] ?? $messageObj['message'] ?? $data['message'] ?? '';
$messageId = $data['id'] ?? $messageObj['reference_id'] ?? $messageObj['id'] ?? uniqid('unisms_in_');
$senderNumber = unisms_clean_number($senderRaw);

if ($senderNumber === '' || trim((string)$message) === '') {
    $senderHash = $senderNumber !== '' ? hash('sha256', $senderNumber) : null;
    error_log('[receive_sms_unisms] Ignored: missing inbound data. sender_hash=' . (string)$senderHash . ' message_present=' . (trim((string)$message) !== '' ? 'yes' : 'no'));
    unisms_json(200, [
        'status' => 'success',
        'message' => 'Webhook ignored',
        'reason' => 'missing_inbound_data',
        'event' => $event,
    ]);
}

$convQuery = $db->collection('conversations')
    ->where('members', 'array-contains', $senderNumber)
    ->orderBy('last_message_at', 'DESC')
    ->documents();

$matchingConvs = [];
foreach ($convQuery as $doc) {
    if ($doc->exists()) {
        $convData = $doc->data();
        $matchingConvs[] = [
            'locId' => $convData['location_id'] ?? null,
            'convId' => $doc->id()
        ];
    }
}

if (empty($matchingConvs)) {
    error_log('[receive_sms_unisms] No recent conversation found. sender_hash=' . hash('sha256', $senderNumber) . ' Ignored to prevent cross-account bleeding.');
    unisms_json(200, [
        'status' => 'success',
        'message' => 'Webhook ignored',
        'reason' => 'unmapped_sender',
        'event' => $event,
    ]);
}

$matchingConvs = array_slice($matchingConvs, 0, 1);
$processed = [];
$now = new \Google\Cloud\Core\Timestamp(new \DateTime());

foreach ($matchingConvs as $matching) {
    $locId = $matching['locId'];
    $convId = $matching['convId'];

    if (!$locId || !$convId) {
        continue;
    }

    $saveData = [
        'conversation_id' => $convId,
        'location_id' => $locId,
        'message_id' => $messageId . '_' . $locId,
        'from' => $senderNumber,
        'message' => $message,
        'direction' => 'inbound',
        'status' => 'Received',
        'date_received' => $now,
        'provider' => 'unisms',
        'provider_reference_id' => (string)$messageId,
        'provider_message_id' => (string)$messageId,
        'provider_status' => $messageObj['status'] ?? $data['status'] ?? $event,
        'provider_response' => $data,
    ];

    MessageSyncService::recordMessageEvent($db, [
        'origin' => 'provider_inbound_unisms',
        'conversation_id' => $convId,
        'conversation_type' => 'direct',
        'conversation_members' => [$senderNumber],
        'location_id' => $locId,
        'message_id' => $saveData['message_id'],
        'from' => $senderNumber,
        'message' => $message,
        'direction' => 'inbound',
        'status' => 'Received',
        'date_received' => $now,
        'timestamp' => $now,
        'provider' => 'unisms',
        'provider_reference_id' => (string)$messageId,
        'provider_message_id' => (string)$messageId,
        'provider_status' => $messageObj['status'] ?? $data['status'] ?? $event,
        'provider_response' => $data,
        'write_inbound_compat' => true,
    ]);

    if (false) {
    $db->collection('messages')->document($saveData['message_id'])->set($saveData, ['merge' => true]);

    $db->collection('conversations')->document($convId)->set([
        'id' => $convId,
        'location_id' => $locId,
        'last_message' => $message,
        'last_message_at' => $now,
        'updated_at' => $now,
        'type' => 'direct',
        'members' => [$senderNumber]
    ], ['merge' => true]);

    try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::deleteRegistry("conversations_registry_{$locId}");
    } catch (\Throwable $cacheEx) {
        error_log("[receive_sms_unisms] Cache invalidation failed: " . $cacheEx->getMessage());
    }
    }

    $processed[] = [
        'locId' => $locId,
        'senderNumber' => $senderNumber,
        'message' => $message,
    ];
}

$response = [
    'status' => 'success',
    'message' => 'Webhook processed',
    'event' => $event,
    'provider' => 'unisms',
    'locations_processed' => array_column($processed, 'locId'),
];

if (!headers_sent()) {
    ignore_user_abort(true);
    set_time_limit(300);

    ob_start();
    echo json_encode($response);

    $size = ob_get_length();
    header("Content-Length: $size");
    header('Connection: close');
    ob_end_flush();
    ob_flush();
    flush();

    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
}

foreach ($processed as $proc) {
    try {
        $ghlSync = new \Nola\Services\GhlSyncService($db, $proc['locId']);
        $ghlSync->syncInboundMessage($proc['senderNumber'], $proc['message']);
    } catch (\Throwable $e) {
        error_log("[receive_sms_unisms] GHL Sync failed for location {$proc['locId']}: " . $e->getMessage());
    }
}
