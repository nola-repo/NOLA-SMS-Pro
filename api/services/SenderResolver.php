<?php

class SenderResolver
{
    public static function integrationDocId(string $locationId): string
    {
        return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    }

    public static function normalizeProvider($value): string
    {
        $provider = strtolower(trim((string)($value ?? 'system')));
        if (in_array($provider, ['unisms', 'unisms_custom'], true)) {
            return 'unisms';
        }
        if (in_array($provider, ['semaphore', 'semaphore_custom'], true)) {
            return 'semaphore';
        }
        return 'system';
    }

    public static function isCustomProviderPreference($value): bool
    {
        return in_array(strtolower(trim((string)$value)), ['unisms_custom', 'semaphore_custom'], true);
    }

    public static function resolve(
        $db,
        string $locationId,
        array $config,
        array $intData,
        ?string $requestedSender = null,
        bool $isSystemNotification = false,
        string $logContext = 'sms'
    ): array {
        $systemSender = $config['SENDER_IDS'][0] ?? 'NOLASMSPro';
        $systemSemaphoreKey = trim((string)($config['SEMAPHORE_API_KEY'] ?? ''));
        $providerPreference = (string)($intData['provider_preference'] ?? 'system');
        $approvedProvider = self::normalizeProvider($providerPreference);
        $approvedSender = trim((string)($intData['approved_sender_id'] ?? ''));
        $unismsSender = trim((string)($intData['unisms_sender_id'] ?? ''));
        $semaphoreCustomKey = trim((string)($intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? '')));
        $unismsCustomKey = trim((string)($intData['unisms_api_key'] ?? ''));

        $selectedProvider = $approvedProvider;
        $activeApiKey = null;
        $apiKeySource = 'provider_default';
        $usingCustomKey = false;

        if ($selectedProvider === 'unisms') {
            if ($unismsCustomKey !== '') {
                $activeApiKey = $unismsCustomKey;
                $apiKeySource = 'integration.unisms_api_key';
                $usingCustomKey = true;
            } else {
                $apiKeySource = 'admin_config.unisms_api_key';
            }
        } elseif ($selectedProvider === 'semaphore') {
            if ($semaphoreCustomKey !== '' && $semaphoreCustomKey !== $systemSemaphoreKey) {
                $activeApiKey = $semaphoreCustomKey;
                $apiKeySource = !empty($intData['nola_pro_api_key'])
                    ? 'integration.nola_pro_api_key'
                    : 'integration.semaphore_api_key';
                $usingCustomKey = true;
            } else {
                $activeApiKey = $systemSemaphoreKey;
                $apiKeySource = 'config.SEMAPHORE_API_KEY';
            }
        } else {
            if ($unismsCustomKey !== '' && self::isCustomProviderPreference($providerPreference)) {
                $selectedProvider = 'unisms';
                $activeApiKey = $unismsCustomKey;
                $apiKeySource = 'integration.unisms_api_key';
                $usingCustomKey = true;
            } elseif ($semaphoreCustomKey !== '' && $semaphoreCustomKey !== $systemSemaphoreKey) {
                $selectedProvider = 'semaphore';
                $activeApiKey = $semaphoreCustomKey;
                $apiKeySource = !empty($intData['nola_pro_api_key'])
                    ? 'integration.nola_pro_api_key'
                    : 'integration.semaphore_api_key';
                $usingCustomKey = true;
            } else {
                $selectedProvider = 'semaphore';
                $activeApiKey = $systemSemaphoreKey;
                $apiKeySource = 'config.SEMAPHORE_API_KEY';
            }
        }

        $masterSenders = self::loadMasterSenders($db);
        $sender = $systemSender;
        $senderSource = 'system_default';

        if ($isSystemNotification) {
            $selectedProvider = 'semaphore';
            $activeApiKey = $systemSemaphoreKey;
            $apiKeySource = 'config.SEMAPHORE_API_KEY';
            $usingCustomKey = false;
            $sender = 'NOLASMSPro';
            $senderSource = 'system_notification_override';
        } elseif (strcasecmp((string)$requestedSender, 'NOLASMSPro') === 0) {
            $selectedProvider = 'semaphore';
            $activeApiKey = $systemSemaphoreKey;
            $apiKeySource = 'config.SEMAPHORE_API_KEY';
            $usingCustomKey = false;
            $sender = 'NOLASMSPro';
            $senderSource = 'explicit_system_sender';
        } elseif ($selectedProvider === 'unisms') {
            if ($unismsSender !== '') {
                $sender = $unismsSender;
                $senderSource = 'integration.unisms_sender_id';
            } elseif ($approvedSender !== '') {
                $sender = $approvedSender;
                $senderSource = 'integration.approved_sender_id';
            } elseif (trim((string)$requestedSender) !== '') {
                $sender = trim((string)$requestedSender);
                $senderSource = 'request.sender';
            }
        } elseif ($usingCustomKey) {
            if ($approvedSender !== '') {
                $sender = $approvedSender;
                $senderSource = 'integration.approved_sender_id';
            } elseif (trim((string)$requestedSender) !== '') {
                $sender = trim((string)$requestedSender);
                $senderSource = 'request.sender';
            }
        } elseif ($approvedSender !== '' && in_array($approvedSender, $masterSenders, true)) {
            $sender = $approvedSender;
            $senderSource = 'integration.approved_sender_id';
        } elseif (trim((string)$requestedSender) !== '' && in_array((string)$requestedSender, $masterSenders, true)) {
            $sender = trim((string)$requestedSender);
            $senderSource = 'request.sender';
        }

        error_log("[SenderResolver][{$logContext}] " . json_encode([
            'location_id' => $locationId,
            'requested_sender' => $requestedSender,
            'approved_sender' => $approvedSender ?: null,
            'approved_provider' => $approvedProvider,
            'selected_provider' => $selectedProvider,
            'sender_name' => $sender,
            'sender_source' => $senderSource,
            'api_key_source' => $apiKeySource,
            'using_custom_key' => $usingCustomKey,
            'provider_preference' => $providerPreference,
        ]));

        return [
            'sender' => $sender,
            'sender_name' => $sender,
            'sender_source' => $senderSource,
            'approved_sender_id' => $approvedSender ?: null,
            'approved_provider' => $approvedProvider,
            'selected_provider' => $selectedProvider,
            'provider_preference' => $selectedProvider,
            'stored_provider_preference' => $providerPreference,
            'active_api_key' => $activeApiKey,
            'api_key_source' => $apiKeySource,
            'using_custom_key' => $usingCustomKey,
            'master_senders' => $masterSenders,
        ];
    }

    public static function resolveStatusApiKey($db, string $locationId, string $providerName, ?string $systemSemaphoreKey, bool $isSystem = false): array
    {
        $providerName = self::normalizeProvider($providerName);
        if ($isSystem) {
            return [
                'api_key' => $providerName === 'semaphore' ? $systemSemaphoreKey : null,
                'source' => $providerName === 'semaphore' ? 'config.SEMAPHORE_API_KEY' : 'admin_config.unisms_api_key',
            ];
        }

        try {
            $snap = $db->collection('integrations')->document(self::integrationDocId($locationId))->snapshot();
            if ($snap->exists()) {
                $data = $snap->data();
                if ($providerName === 'unisms' && !empty($data['unisms_api_key'])) {
                    return ['api_key' => $data['unisms_api_key'], 'source' => 'integration.unisms_api_key'];
                }
                if ($providerName === 'semaphore') {
                    $key = $data['nola_pro_api_key'] ?? ($data['semaphore_api_key'] ?? null);
                    if (!empty($key)) {
                        return ['api_key' => $key, 'source' => !empty($data['nola_pro_api_key']) ? 'integration.nola_pro_api_key' : 'integration.semaphore_api_key'];
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('[SenderResolver][status_key] ' . json_encode([
                'location_id' => $locationId,
                'provider' => $providerName,
                'error' => $e->getMessage(),
            ]));
        }

        return [
            'api_key' => $providerName === 'semaphore' ? $systemSemaphoreKey : null,
            'source' => $providerName === 'semaphore' ? 'config.SEMAPHORE_API_KEY' : 'admin_config.unisms_api_key',
        ];
    }

    private static function loadMasterSenders($db): array
    {
        try {
            $snap = $db->collection('admin_config')->document('master_senders')->snapshot();
            if ($snap->exists()) {
                $senders = $snap->data()['approved_senders'] ?? ['NOLASMSPro'];
                return is_array($senders) ? $senders : ['NOLASMSPro'];
            }
        } catch (\Throwable $e) {
            error_log('[SenderResolver] master sender load failed: ' . $e->getMessage());
        }

        return ['NOLASMSPro'];
    }
}
