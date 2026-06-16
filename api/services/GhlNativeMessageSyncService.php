<?php

require_once __DIR__ . '/MessageSyncService.php';
require_once __DIR__ . '/GhlClient.php';

class GhlNativeMessageSyncService
{
    public static function verifyOptionalSignature(array $config, string $rawBody): array
    {
        $secret = trim((string)($config['GHL_WEBHOOK_SIGNING_SECRET'] ?? ''));
        if ($secret === '') {
            return ['success' => true, 'verified' => false, 'reason' => 'signing_secret_not_configured'];
        }

        $signature = self::headerValue([
            'X-GHL-Signature',
            'X-Leadconnector-Signature',
            'X-LC-Signature',
            'X-Webhook-Signature',
        ]);

        if ($signature === '') {
            return ['success' => true, 'verified' => false, 'reason' => 'signature_header_missing'];
        }

        $received = strtolower(trim((string)preg_replace('/^sha256=/i', '', $signature)));
        $expected = hash_hmac('sha256', $rawBody, $secret);

        return [
            'success' => hash_equals($expected, $received),
            'verified' => true,
            'reason' => hash_equals($expected, $received) ? 'signature_valid' : 'signature_invalid',
        ];
    }

    public static function recordConversationMessagePayload($db, array $payload, string $origin = 'ghl_native_webhook'): array
    {
        $event = self::mapPayloadToMessageEvent($payload, $origin);
        $result = MessageSyncService::recordMessageEvent($db, $event);

        error_log('[GhlNativeMessageSyncService] recorded ' . json_encode([
            'origin' => $origin,
            'location_id' => $event['location_id'] ?? null,
            'conversation_id' => $result['conversation_id'] ?? null,
            'message_id' => $result['message_id'] ?? null,
            'ghl_message_id' => $event['ghl_message_id'] ?? null,
            'ghl_conversation_id' => $event['ghl_conversation_id'] ?? null,
            'direction' => $event['direction'] ?? null,
            'status' => $result['status'] ?? null,
        ]));

        return $result + [
            'ghl_message_id' => $event['ghl_message_id'] ?? null,
            'ghl_conversation_id' => $event['ghl_conversation_id'] ?? null,
        ];
    }

    public static function reconcile($db, ?string $locationId = null, int $limit = 25, int $sinceMinutes = 15): array
    {
        $limit = max(1, min($limit, 100));
        $sinceMinutes = max(1, min($sinceMinutes, 1440));
        $locations = $locationId ? [$locationId] : self::discoverLocations($db, 50);
        $summary = [
            'success' => true,
            'locations_checked' => count($locations),
            'locations' => [],
            'messages_recorded' => 0,
            'errors' => [],
        ];

        foreach ($locations as $locId) {
            $cursor = self::loadCursor($db, $locId, $sinceMinutes);
            $locationResult = [
                'location_id' => $locId,
                'cursor_after' => $cursor->format(DATE_ATOM),
                'conversations_checked' => 0,
                'messages_recorded' => 0,
                'errors' => [],
            ];

            try {
                $client = new GhlClient($db, $locId);
                $conversations = self::fetchUpdatedConversations($client, $locId, $cursor, $limit);
                $latestSeen = $cursor;

                foreach ($conversations as $conversation) {
                    $locationResult['conversations_checked']++;
                    $conversationId = self::firstNonEmpty([
                        $conversation['id'] ?? null,
                        $conversation['conversationId'] ?? null,
                    ]);
                    if ($conversationId === null) {
                        continue;
                    }

                    $conversationUpdatedAt = self::extractDateTime($conversation, ['dateUpdated', 'updatedAt', 'lastMessageDate', 'lastMessageAt']);
                    if ($conversationUpdatedAt && $conversationUpdatedAt > $latestSeen) {
                        $latestSeen = $conversationUpdatedAt;
                    }

                    foreach (self::fetchConversationMessages($client, $locId, $conversationId, $limit) as $message) {
                        $messageAt = self::extractDateTime($message, ['dateAdded', 'dateCreated', 'createdAt', 'updatedAt']);
                        if ($messageAt && $messageAt < $cursor) {
                            continue;
                        }

                        $payload = $message;
                        $payload['locationId'] = $payload['locationId'] ?? $locId;
                        $payload['conversationId'] = $payload['conversationId'] ?? $conversationId;
                        $payload['contactId'] = $payload['contactId'] ?? ($conversation['contactId'] ?? null);
                        $payload['contact'] = $payload['contact'] ?? ($conversation['contact'] ?? null);
                        $payload['conversation'] = $payload['conversation'] ?? $conversation;

                        self::recordConversationMessagePayload($db, $payload, 'ghl_native_reconcile');
                        $locationResult['messages_recorded']++;
                        $summary['messages_recorded']++;

                        if ($messageAt && $messageAt > $latestSeen) {
                            $latestSeen = $messageAt;
                        }
                    }
                }

                self::saveCursor($db, $locId, $latestSeen, 'ok', null);
                $locationResult['cursor_after'] = $latestSeen->format(DATE_ATOM);
            } catch (\Throwable $e) {
                $summary['success'] = false;
                $error = [
                    'location_id' => $locId,
                    'message' => $e->getMessage(),
                ];
                $summary['errors'][] = $error;
                $locationResult['errors'][] = $error;
                self::saveCursor($db, $locId, $cursor, 'error', $e->getMessage());
                error_log('[GhlNativeMessageSyncService] reconcile error ' . json_encode($error));
            }

            $summary['locations'][] = $locationResult;
        }

        return $summary;
    }

    private static function mapPayloadToMessageEvent(array $payload, string $origin): array
    {
        $message = self::messageNode($payload);
        $conversation = self::arrayValue($payload['conversation'] ?? null);
        $contact = self::arrayValue($payload['contact'] ?? null);

        $locationId = self::firstNonEmpty([
            $payload['locationId'] ?? null,
            $payload['location_id'] ?? null,
            $message['locationId'] ?? null,
            $message['location_id'] ?? null,
            $conversation['locationId'] ?? null,
            $conversation['location_id'] ?? null,
            self::nested($payload, ['location', 'id']),
            self::nested($payload, ['customData', 'location_id']),
            self::nested($payload, ['customData', 'locationId']),
        ]);

        if ($locationId === null) {
            throw new \InvalidArgumentException('Missing location_id in GHL conversation message payload');
        }

        $ghlMessageId = self::firstNonEmpty([
            $payload['messageId'] ?? null,
            $payload['message_id'] ?? null,
            $message['messageId'] ?? null,
            $message['message_id'] ?? null,
            $message['id'] ?? null,
            $payload['id'] ?? null,
        ]);

        $ghlConversationId = self::firstNonEmpty([
            $payload['conversationId'] ?? null,
            $payload['conversation_id'] ?? null,
            $message['conversationId'] ?? null,
            $message['conversation_id'] ?? null,
            $conversation['id'] ?? null,
            $conversation['conversationId'] ?? null,
        ]);

        $ghlContactId = self::firstNonEmpty([
            $payload['contactId'] ?? null,
            $payload['contact_id'] ?? null,
            $message['contactId'] ?? null,
            $message['contact_id'] ?? null,
            $conversation['contactId'] ?? null,
            $contact['id'] ?? null,
        ]);

        $direction = self::normalizeDirection(self::firstNonEmpty([
            $payload['direction'] ?? null,
            $payload['messageDirection'] ?? null,
            $payload['message_direction'] ?? null,
            $message['direction'] ?? null,
            $message['messageDirection'] ?? null,
            $message['type'] ?? null,
            $payload['type'] ?? null,
        ]));

        $phone = self::firstNonEmpty($direction === 'outbound'
            ? [
                $message['to'] ?? null,
                $payload['to'] ?? null,
                $contact['phone'] ?? null,
                $contact['phoneNumber'] ?? null,
                $conversation['phone'] ?? null,
                $message['phone'] ?? null,
                $payload['phone'] ?? null,
            ]
            : [
                $message['from'] ?? null,
                $payload['from'] ?? null,
                $contact['phone'] ?? null,
                $contact['phoneNumber'] ?? null,
                $conversation['phone'] ?? null,
                $message['phone'] ?? null,
                $payload['phone'] ?? null,
            ]);

        $cleanPhone = self::cleanPhone((string)($phone ?? ''));
        $body = self::firstNonEmpty([
            $message['message'] ?? null,
            $payload['message'] ?? null,
            $message['body'] ?? null,
            $payload['body'] ?? null,
            $message['text'] ?? null,
            $payload['text'] ?? null,
            $message['content'] ?? null,
            $payload['content'] ?? null,
            $message['messageBody'] ?? null,
            $payload['messageBody'] ?? null,
        ]) ?? '';

        $timestamp = self::extractDateTime($message, ['dateAdded', 'dateCreated', 'createdAt', 'updatedAt'])
            ?? self::extractDateTime($payload, ['dateAdded', 'dateCreated', 'createdAt', 'updatedAt', 'timestamp'])
            ?? new \DateTimeImmutable();

        $name = self::firstNonEmpty([
            $contact['name'] ?? null,
            trim((string)(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''))),
            $conversation['fullName'] ?? null,
            $conversation['contactName'] ?? null,
            $payload['name'] ?? null,
        ]);

        $conversationId = $cleanPhone !== ''
            ? ((string)$locationId . '_conv_' . $cleanPhone)
            : ((string)$locationId . '_ghl_' . self::docId((string)($ghlConversationId ?? 'unknown')));

        $stableMessageId = $ghlMessageId ?: 'ghl_evt_' . hash('sha256', json_encode([
            $origin,
            $locationId,
            $ghlConversationId,
            $ghlContactId,
            $direction,
            $cleanPhone,
            $body,
            $timestamp->format(DATE_ATOM),
        ]));

        $status = self::firstNonEmpty([
            $message['status'] ?? null,
            $payload['status'] ?? null,
            $message['deliveryStatus'] ?? null,
            $payload['deliveryStatus'] ?? null,
        ]);

        return [
            'origin' => $origin,
            'source' => 'ghl_native',
            'location_id' => (string)$locationId,
            'conversation_id' => $conversationId,
            'conversation_members' => $cleanPhone !== '' ? [$cleanPhone] : [],
            'conversation_name' => $name,
            'message_id' => $stableMessageId,
            'ghl_message_id' => $ghlMessageId,
            'ghl_conversation_id' => $ghlConversationId,
            'ghl_contact_id' => $ghlContactId,
            'provider' => 'ghl_native',
            'provider_reference_id' => $ghlMessageId,
            'provider_message_id' => $ghlMessageId,
            'provider_status' => $status,
            'direction' => $direction,
            'number' => $cleanPhone,
            'from' => $direction === 'inbound' ? $cleanPhone : null,
            'to' => $direction === 'outbound' ? $cleanPhone : null,
            'message' => (string)$body,
            'status' => $direction === 'outbound' ? ($status ?? 'Sent') : 'Received',
            'timestamp' => $timestamp,
            'created_at' => new \Google\Cloud\Core\Timestamp($timestamp),
            'date_created' => new \Google\Cloud\Core\Timestamp($timestamp),
            'date_received' => $direction === 'inbound' ? new \Google\Cloud\Core\Timestamp($timestamp) : null,
            'last_message_at' => new \Google\Cloud\Core\Timestamp($timestamp),
            'updated_at' => new \Google\Cloud\Core\Timestamp($timestamp),
            'name' => $name,
            'write_inbound_compat' => $direction === 'inbound',
            'log_source' => 'ghl_native',
        ];
    }

    private static function fetchUpdatedConversations(GhlClient $client, string $locationId, \DateTimeImmutable $cursor, int $limit): array
    {
        $paths = [
            '/conversations/search?locationId=' . urlencode($locationId) . '&limit=' . $limit,
            '/conversations/?locationId=' . urlencode($locationId) . '&limit=' . $limit,
        ];

        foreach ($paths as $path) {
            $resp = $client->request('GET', $path, null, '2021-04-15');
            if (($resp['status'] ?? 500) >= 300) {
                error_log('[GhlNativeMessageSyncService] conversation fetch failed HTTP ' . ($resp['status'] ?? 0) . ' path=' . $path);
                continue;
            }

            $data = json_decode((string)($resp['body'] ?? ''), true);
            $items = self::firstArrayList([
                $data['conversations'] ?? null,
                $data['data'] ?? null,
                $data['items'] ?? null,
            ]);

            if ($items !== null) {
                return array_values(array_filter($items, static function ($conversation) use ($cursor) {
                    if (!is_array($conversation)) {
                        return false;
                    }
                    $updatedAt = self::extractDateTime($conversation, ['dateUpdated', 'updatedAt', 'lastMessageDate', 'lastMessageAt']);
                    return $updatedAt === null || $updatedAt >= $cursor;
                }));
            }
        }

        return [];
    }

    private static function fetchConversationMessages(GhlClient $client, string $locationId, string $conversationId, int $limit): array
    {
        $paths = [
            '/conversations/' . urlencode($conversationId) . '/messages?locationId=' . urlencode($locationId) . '&limit=' . $limit,
            '/conversations/messages?conversationId=' . urlencode($conversationId) . '&locationId=' . urlencode($locationId) . '&limit=' . $limit,
        ];

        foreach ($paths as $path) {
            $resp = $client->request('GET', $path, null, '2021-04-15');
            if (($resp['status'] ?? 500) >= 300) {
                error_log('[GhlNativeMessageSyncService] message fetch failed HTTP ' . ($resp['status'] ?? 0) . ' path=' . $path);
                continue;
            }

            $data = json_decode((string)($resp['body'] ?? ''), true);
            $items = self::firstArrayList([
                $data['messages'] ?? null,
                $data['data'] ?? null,
                $data['items'] ?? null,
            ]);

            if ($items !== null) {
                return $items;
            }
        }

        return [];
    }

    private static function discoverLocations($db, int $limit): array
    {
        $locations = [];
        foreach ($db->collection('ghl_tokens')->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            $locId = self::firstNonEmpty([
                $data['location_id'] ?? null,
                $data['locationId'] ?? null,
                (($data['userType'] ?? null) === 'Location') ? $doc->id() : null,
            ]);
            if ($locId !== null) {
                $locations[$locId] = true;
            }
            if (count($locations) >= $limit) {
                break;
            }
        }
        return array_keys($locations);
    }

    private static function loadCursor($db, string $locationId, int $sinceMinutes): \DateTimeImmutable
    {
        $fallback = new \DateTimeImmutable("-{$sinceMinutes} minutes");
        $snap = $db->collection('sync_cursors')->document(self::cursorDocId($locationId))->snapshot();
        if (!$snap->exists()) {
            return $fallback;
        }

        $data = $snap->data();
        $stored = $data['last_cursor_at'] ?? null;
        if ($stored instanceof \Google\Cloud\Core\Timestamp) {
            return \DateTimeImmutable::createFromInterface($stored->get());
        }
        if (is_string($stored) && trim($stored) !== '') {
            try {
                return new \DateTimeImmutable($stored);
            } catch (\Throwable $e) {
                return $fallback;
            }
        }
        return $fallback;
    }

    private static function saveCursor($db, string $locationId, \DateTimeImmutable $cursor, string $status, ?string $error): void
    {
        $payload = [
            'location_id' => $locationId,
            'sync_name' => 'ghl_native_message_sync',
            'last_cursor_at' => new \Google\Cloud\Core\Timestamp($cursor),
            'last_run_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
            'last_run_status' => $status,
        ];
        if ($error !== null) {
            $payload['last_error'] = $error;
        }
        $db->collection('sync_cursors')->document(self::cursorDocId($locationId))->set($payload, ['merge' => true]);
    }

    private static function cursorDocId(string $locationId): string
    {
        return 'ghl_native_' . self::docId($locationId);
    }

    private static function messageNode(array $payload): array
    {
        foreach (['message', 'messageData', 'message_data', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return $payload[$key];
            }
        }
        return $payload;
    }

    private static function normalizeDirection(?string $value): string
    {
        $raw = strtolower(trim((string)$value));
        if (str_contains($raw, 'outbound') || str_contains($raw, 'outgoing') || str_contains($raw, 'sent')) {
            return 'outbound';
        }
        if (str_contains($raw, 'inbound') || str_contains($raw, 'incoming') || str_contains($raw, 'received')) {
            return 'inbound';
        }
        return 'inbound';
    }

    private static function extractDateTime(array $data, array $keys): ?\DateTimeImmutable
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                return \DateTimeImmutable::createFromInterface($value->get());
            }
            if ($value instanceof \DateTimeInterface) {
                return \DateTimeImmutable::createFromInterface($value);
            }
            if (is_numeric($value)) {
                $seconds = (int)$value;
                if ($seconds > 9999999999) {
                    $seconds = (int)floor($seconds / 1000);
                }
                return (new \DateTimeImmutable())->setTimestamp($seconds);
            }
            if (is_string($value) && trim($value) !== '') {
                try {
                    return new \DateTimeImmutable($value);
                } catch (\Throwable $e) {
                    continue;
                }
            }
        }
        return null;
    }

    private static function cleanPhone(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);
        if (str_starts_with($digits, '639') && strlen($digits) === 12) {
            return '0' . substr($digits, 2);
        }
        if (str_starts_with($digits, '9') && strlen($digits) === 10) {
            return '0' . $digits;
        }
        return $digits;
    }

    private static function docId(string $value): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $value);
        return substr($clean ?: hash('sha256', $value), 0, 400);
    }

    private static function firstArrayList(array $values): ?array
    {
        foreach ($values as $value) {
            $list = self::asList($value);
            if ($list !== null) {
                return $list;
            }
            if (is_array($value)) {
                foreach (['messages', 'conversations', 'data', 'items', 'results'] as $key) {
                    $list = self::asList($value[$key] ?? null);
                    if ($list !== null) {
                        return $list;
                    }
                }
            }
        }
        return null;
    }

    private static function asList($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $keys = array_keys($value);
        if ($keys === []) {
            return [];
        }
        if ($keys === range(0, count($value) - 1)) {
            return $value;
        }
        if (isset($value[0])) {
            return array_values($value);
        }
        return null;
    }

    private static function arrayValue($value): array
    {
        return is_array($value) ? $value : [];
    }

    private static function nested(array $data, array $path)
    {
        $current = $data;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }
        return $current;
    }

    private static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }
            if ($value !== null && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return null;
    }

    private static function headerValue(array $names): string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($names as $name) {
            $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
            if (!empty($_SERVER[$serverKey])) {
                return (string)$_SERVER[$serverKey];
            }
            foreach ($headers as $key => $value) {
                if (strcasecmp($key, $name) === 0) {
                    return (string)$value;
                }
            }
        }
        return '';
    }
}
