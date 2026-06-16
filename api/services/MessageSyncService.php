<?php

require_once __DIR__ . '/../cache_helper.php';

class MessageSyncService
{
    public static function recordMessageEvent($db, array $event): array
    {
        $locationId = trim((string)($event['location_id'] ?? ''));
        if ($locationId === '') {
            throw new \InvalidArgumentException('MessageSyncService requires location_id');
        }

        $direction = strtolower(trim((string)($event['direction'] ?? 'outbound')));
        $isOutbound = $direction === 'outbound';
        $messageId = self::resolveMessageId($event);
        $now = self::timestamp($event['timestamp'] ?? null);

        $conversationId = (string)($event['conversation_id'] ?? self::conversationId($locationId, $event));
        $recipient = self::cleanPhone((string)($event['number'] ?? $event['to'] ?? $event['from'] ?? ''));
        $members = $event['conversation_members'] ?? ($recipient !== '' ? [$recipient] : []);
        $messageText = (string)($event['message'] ?? '');
        $status = self::normalizeStatus($event['status'] ?? null, $direction);
        $providerReferenceId = self::firstNonEmpty([
            $event['provider_reference_id'] ?? null,
            $event['provider_message_id'] ?? null,
            $event['message_id'] ?? null,
        ]);
        $providerMessageId = self::firstNonEmpty([
            $event['provider_message_id'] ?? null,
            $providerReferenceId,
        ]);

        $messageData = array_filter([
            'conversation_id' => $conversationId,
            'location_id' => $locationId,
            'message_id' => $messageId,
            'number' => $isOutbound ? ($recipient ?: null) : null,
            'from' => !$isOutbound ? ($recipient ?: ($event['from'] ?? null)) : null,
            'message' => $messageText,
            'direction' => $direction,
            'sender_id' => $event['sender_id'] ?? null,
            'sender_name' => $event['sender_name'] ?? ($event['sender_id'] ?? null),
            'status' => $status,
            'batch_id' => $event['batch_id'] ?? null,
            'recipient_key' => $event['recipient_key'] ?? null,
            'created_at' => $event['created_at'] ?? $now,
            'date_created' => $event['date_created'] ?? $now,
            'date_received' => !$isOutbound ? ($event['date_received'] ?? $now) : null,
            'name' => $event['name'] ?? null,
            'segments' => $event['segments'] ?? null,
            'source' => $event['source'] ?? null,
            'provider' => $event['provider'] ?? null,
            'provider_reference_id' => $providerReferenceId,
            'provider_message_id' => $providerMessageId,
            'provider_status' => $event['provider_status'] ?? null,
            'provider_response' => $event['provider_response'] ?? null,
            'provider_error' => $event['provider_error'] ?? null,
            'ghl_message_id' => $event['ghl_message_id'] ?? null,
            'ghl_conversation_id' => $event['ghl_conversation_id'] ?? null,
            'ghl_contact_id' => $event['ghl_contact_id'] ?? null,
            'idempotency_key' => $event['idempotency_key'] ?? null,
            'is_system' => $event['is_system'] ?? null,
        ], static fn($v) => $v !== null);

        $db->collection('messages')->document($messageId)->set($messageData, ['merge' => true]);

        if ($isOutbound) {
            $logData = array_filter([
                'message_id' => $messageId,
                'location_id' => $locationId,
                'numbers' => $event['numbers'] ?? ($recipient !== '' ? [$recipient] : []),
                'message' => $messageText,
                'sender_id' => $event['sender_id'] ?? null,
                'sender_name' => $event['sender_name'] ?? ($event['sender_id'] ?? null),
                'status' => $status,
                'date_created' => $event['date_created'] ?? $now,
                'source' => $event['log_source'] ?? ($event['provider'] ?? ($event['source'] ?? null)),
                'provider' => $event['provider'] ?? null,
                'batch_id' => $event['batch_id'] ?? null,
                'recipient_key' => $event['recipient_key'] ?? null,
                'credits_used' => $event['credits_used'] ?? ($event['segments'] ?? null),
                'conversation_id' => $conversationId,
                'provider_reference_id' => $providerReferenceId,
                'provider_message_id' => $providerMessageId,
                'provider_status' => $event['provider_status'] ?? null,
                'provider_error' => $event['provider_error'] ?? null,
                'provider_response' => $event['provider_response'] ?? null,
                'ghl_message_id' => $event['ghl_message_id'] ?? null,
                'idempotency_key' => $event['idempotency_key'] ?? null,
                'is_system' => $event['is_system'] ?? null,
            ], static fn($v) => $v !== null);

            $db->collection('sms_logs')->document($messageId)->set($logData, ['merge' => true]);
        } elseif (!empty($event['write_inbound_compat'])) {
            $db->collection('inbound_messages')->document($messageId)->set([
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'location_id' => $locationId,
                'from' => $recipient ?: ($event['from'] ?? null),
                'message' => $messageText,
                'direction' => 'inbound',
                'status' => $status,
                'date_received' => $event['date_received'] ?? $now,
                'provider' => $event['provider'] ?? null,
                'provider_reference_id' => $providerReferenceId,
                'provider_message_id' => $providerMessageId,
                'provider_status' => $event['provider_status'] ?? null,
                'provider_response' => $event['provider_response'] ?? null,
            ], ['merge' => true]);
        }

        $conversationData = [
            'id' => $conversationId,
            'location_id' => $locationId,
            'last_message' => $messageText,
            'last_message_at' => $event['last_message_at'] ?? $now,
            'updated_at' => $event['updated_at'] ?? $now,
            'type' => $event['conversation_type'] ?? (!empty($event['batch_id']) ? 'group' : 'direct'),
        ];

        if (!empty($event['conversation_name'])) {
            $conversationData['name'] = $event['conversation_name'];
        } elseif (!empty($event['name'])) {
            $conversationData['name'] = $event['name'];
        }

        foreach (['ghl_contact_id', 'ghl_conversation_id'] as $ghlField) {
            if (!empty($event[$ghlField])) {
                $conversationData[$ghlField] = $event[$ghlField];
            }
        }

        if (!empty($event['append_members'])) {
            $conversationData['members'] = \Google\Cloud\Firestore\FieldValue::arrayUnion(array_values(array_filter($members)));
        } else {
            $conversationData['members'] = array_values(array_filter($members));
        }

        $db->collection('conversations')->document($conversationId)->set($conversationData, ['merge' => true]);

        try {
            NolaCache::deleteRegistry("conversations_registry_{$locationId}");
        } catch (\Throwable $e) {
            error_log('[MessageSyncService] cache invalidation failed: ' . $e->getMessage());
        }

        error_log('[MessageSyncService] recordMessageEvent ' . json_encode([
            'origin' => $event['origin'] ?? null,
            'location_id' => $locationId,
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'direction' => $direction,
            'status' => $status,
            'provider' => $event['provider'] ?? null,
            'provider_message_id' => $providerMessageId,
            'ghl_message_id' => $event['ghl_message_id'] ?? null,
        ]));

        return [
            'message_id' => $messageId,
            'conversation_id' => $conversationId,
            'status' => $status,
        ];
    }

    public static function normalizeStatus($status, string $direction = 'outbound'): string
    {
        $raw = strtolower(trim((string)($status ?? '')));
        if ($direction === 'inbound') {
            return 'Received';
        }
        if (in_array($raw, ['sent', 'success', 'delivered'])) {
            return 'Sent';
        }
        if (in_array($raw, ['failed', 'expired', 'rejected', 'undelivered'])) {
            return 'Failed';
        }
        return 'Sending';
    }

    private static function resolveMessageId(array $event): string
    {
        $id = self::firstNonEmpty([
            $event['message_id'] ?? null,
            $event['provider_message_id'] ?? null,
            $event['provider_reference_id'] ?? null,
            $event['ghl_message_id'] ?? null,
        ]);
        if ($id !== null) {
            return self::docId((string)$id);
        }

        return 'evt_' . hash('sha256', json_encode([
            $event['origin'] ?? null,
            $event['location_id'] ?? null,
            $event['conversation_id'] ?? null,
            $event['direction'] ?? null,
            $event['number'] ?? $event['from'] ?? null,
            $event['message'] ?? null,
            $event['timestamp'] ?? null,
        ]));
    }

    private static function conversationId(string $locationId, array $event): string
    {
        if (!empty($event['batch_id'])) {
            return $locationId . '_group_' . self::docId((string)$event['batch_id']);
        }
        $number = self::cleanPhone((string)($event['number'] ?? $event['to'] ?? $event['from'] ?? ''));
        return $locationId . '_conv_' . ($number !== '' ? $number : substr(self::resolveMessageId($event), 0, 24));
    }

    private static function timestamp($value): \Google\Cloud\Core\Timestamp
    {
        if ($value instanceof \Google\Cloud\Core\Timestamp) {
            return $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return new \Google\Cloud\Core\Timestamp($value);
        }
        return new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
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

    private static function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && trim((string)$value) !== '') {
                return (string)$value;
            }
        }
        return null;
    }
}
