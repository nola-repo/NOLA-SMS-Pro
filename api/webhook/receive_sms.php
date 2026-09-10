<?php
require_once __DIR__ . '/../cors.php';

header('Content-Type: application/json');

function receive_sms_respond(int $statusCode, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($statusCode);
    }
    echo json_encode($payload);
    exit;
}

try {
    $config = require __DIR__ . '/config.php';
    require __DIR__ . '/firestore_client.php';
    require_once __DIR__ . '/../services/GhlSyncService.php';
    require_once __DIR__ . '/../services/MessageSyncService.php';
    require_once __DIR__ . '/../services/SenderResolver.php';
    require_once __DIR__ . '/../services/PhoneNormalizer.php';
    require_once __DIR__ . '/../auth_helpers.php';

    validate_api_request();

    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        $data = $_POST ?: [];
    }

    if ($data === []) {
        error_log('[receive_sms] ignored empty payload keys=' . json_encode(array_keys(json_decode($raw ?: '[]', true) ?: [])));
        receive_sms_respond(200, ['status' => 'ignored', 'reason' => 'empty_payload']);
    }

    error_log('[receive_sms] ingress keys=' . json_encode(array_keys($data)));

    $senderNumberRaw = (string)($data['sender'] ?? $data['from'] ?? $data['number'] ?? '');
    $senderNumber = PhoneNormalizer::philippineMobile($senderNumberRaw) ?: trim($senderNumberRaw);
    $message = (string)($data['message'] ?? $data['content'] ?? $data['text'] ?? '');
    $message_id = (string)($data['message_id'] ?? $data['id'] ?? uniqid('inb_', true));
    $destinationSender = SenderResolver::extractInboundDestinationSender($data);

    if ($senderNumber === '') {
        receive_sms_respond(200, ['status' => 'ignored', 'reason' => 'missing_sender']);
    }

    $db = get_firestore();
    $phoneKeys = SenderResolver::inboundPhoneLookupKeys($senderNumberRaw !== '' ? $senderNumberRaw : $senderNumber);

    $locId = null;
    $routeSource = 'none';
    if ($destinationSender !== '') {
        $locId = SenderResolver::resolveLocationByApprovedSender($db, $destinationSender);
        $routeSource = $locId ? 'destination_sender' : 'destination_sender_unmapped';
    }

    $matchingConvs = [];

    if ($locId) {
        $canonicalConvId = $locId . '_conv_' . $senderNumber;
        try {
            $canonicalSnap = $db->collection('conversations')->document($canonicalConvId)->snapshot();
            if ($canonicalSnap->exists()) {
                $matchingConvs[] = ['locId' => $locId, 'convId' => $canonicalConvId];
            }
        } catch (\Throwable $e) {
            error_log('[receive_sms] canonical conversation read failed: ' . $e->getMessage());
        }

        if ($matchingConvs === []) {
            foreach ($phoneKeys as $phoneKey) {
                try {
                    $convQuery = $db->collection('conversations')
                        ->where('location_id', '=', $locId)
                        ->where('members', 'array-contains', $phoneKey)
                        ->limit(5)
                        ->documents();
                    foreach ($convQuery as $doc) {
                        if ($doc->exists()) {
                            $matchingConvs[] = ['locId' => $locId, 'convId' => $doc->id()];
                        }
                    }
                } catch (\Throwable $e) {
                    error_log('[receive_sms] scoped conversation query failed: ' . $e->getMessage());
                }
                if ($matchingConvs !== []) {
                    break;
                }
            }
        }

        if ($matchingConvs === []) {
            $matchingConvs[] = ['locId' => $locId, 'convId' => $canonicalConvId];
            $routeSource .= '+created';
        }
    } else {
        $foundByLocation = [];
        foreach ($phoneKeys as $phoneKey) {
            try {
                $convQuery = $db->collection('conversations')
                    ->where('members', 'array-contains', $phoneKey)
                    ->orderBy('last_message_at', 'DESC')
                    ->limit(8)
                    ->documents();
                foreach ($convQuery as $doc) {
                    if (!$doc->exists()) {
                        continue;
                    }
                    $convData = $doc->data();
                    $foundLoc = (string)($convData['location_id'] ?? '');
                    if ($foundLoc === '') {
                        continue;
                    }
                    $foundByLocation[$foundLoc] = $doc->id();
                }
            } catch (\Throwable $e) {
                error_log('[receive_sms] conversation phone query failed: ' . $e->getMessage());
            }
        }

        if (count($foundByLocation) === 1) {
            $locId = array_key_first($foundByLocation);
            $matchingConvs[] = ['locId' => $locId, 'convId' => $foundByLocation[$locId]];
            $routeSource = 'unique_phone_conversation';
        } elseif (count($foundByLocation) > 1) {
            error_log('[receive_sms] ambiguous phone across locations=' . implode(',', array_keys($foundByLocation)) . ' dest=' . $destinationSender);
            receive_sms_respond(200, [
                'status' => 'ignored',
                'reason' => 'ambiguous_tenant',
                'destination_sender' => $destinationSender ?: null,
            ]);
        }
    }

    if ($matchingConvs === []) {
        error_log('[receive_sms] unmapped sender=' . $senderNumber . ' dest=' . $destinationSender . ' route=' . $routeSource);
        receive_sms_respond(200, [
            'status' => 'ignored',
            'reason' => 'unmapped_sender',
            'destination_sender' => $destinationSender ?: null,
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
            'message_id' => $message_id . '_' . $locId,
            'from' => $senderNumber,
            'message' => $message,
            'direction' => 'inbound',
            'status' => 'Received',
            'date_received' => $now,
        ];

        MessageSyncService::recordMessageEvent($db, [
            'origin' => 'provider_inbound_semaphore',
            'conversation_id' => $convId,
            'conversation_type' => 'direct',
            'conversation_members' => [$senderNumber],
            'location_id' => $locId,
            'message_id' => $saveData['message_id'],
            'from' => $senderNumber,
            'number' => $senderNumber,
            'message' => $message,
            'direction' => 'inbound',
            'status' => 'Received',
            'date_received' => $now,
            'timestamp' => $now,
            'write_inbound_compat' => true,
            'sender_id' => $destinationSender !== '' ? $destinationSender : null,
        ]);

        $processed[] = [
            'locId' => $locId,
            'senderNumber' => $senderNumber,
            'message' => $message,
        ];
    }

    error_log('[receive_sms] routed ' . json_encode([
        'locations' => array_column($processed, 'locId'),
        'destination_sender' => $destinationSender ?: null,
        'route_source' => $routeSource,
    ]));

    if (!headers_sent()) {
        ignore_user_abort(true);
        set_time_limit(300);

        ob_start();
        echo json_encode([
            'status' => 'received',
            'locations_processed' => array_column($processed, 'locId'),
            'route_source' => $routeSource,
        ]);

        $size = ob_get_length();
        header('Content-Length: ' . $size);
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
            error_log('[receive_sms] GHL Sync failed for location ' . $proc['locId'] . ': ' . $e->getMessage());
        }
    }
} catch (\Throwable $e) {
    error_log('[receive_sms] handler error: ' . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(200);
        header('Content-Type: application/json');
    }
    echo json_encode([
        'status' => 'ignored',
        'reason' => 'handler_error',
    ]);
}
