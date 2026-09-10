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
        // SEMAPHORE_GLOBAL_API_KEY is the shared key written to all migrated subaccounts.
        // If the env var is not set in Cloud Run, fall back to the system key so comparisons
        // below still work correctly and don't misclassify migrated accounts as custom-key users.
        $globalSemaphoreKey = trim((string)($config['SEMAPHORE_GLOBAL_API_KEY'] ?? ''));
        if ($globalSemaphoreKey === '') {
            $globalSemaphoreKey = $systemSemaphoreKey;
        }
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
            // A stored key that matches the global OR system key is not a unique custom key —
            // it was written by migrate_api_keys.php and should be treated as the shared pool key.
            $isGlobalOrSystemKey = (
                $semaphoreCustomKey === $globalSemaphoreKey ||
                $semaphoreCustomKey === $systemSemaphoreKey
            );
            if ($semaphoreCustomKey !== '' && !$isGlobalOrSystemKey) {
                $activeApiKey = $semaphoreCustomKey;
                $apiKeySource = !empty($intData['nola_pro_api_key'])
                    ? 'integration.nola_pro_api_key'
                    : 'integration.semaphore_api_key';
                $usingCustomKey = true;
            } else {
                // Use global key if available (preferred), otherwise fall back to system key
                $activeApiKey = $globalSemaphoreKey ?: $systemSemaphoreKey;
                $apiKeySource = $globalSemaphoreKey && $globalSemaphoreKey !== $systemSemaphoreKey
                    ? 'config.SEMAPHORE_GLOBAL_API_KEY'
                    : 'config.SEMAPHORE_API_KEY';
                $usingCustomKey = false;
            }
        } else {
            if ($unismsCustomKey !== '' && self::isCustomProviderPreference($providerPreference)) {
                $selectedProvider = 'unisms';
                $activeApiKey = $unismsCustomKey;
                $apiKeySource = 'integration.unisms_api_key';
                $usingCustomKey = true;
            } elseif ($semaphoreCustomKey !== '' && $semaphoreCustomKey !== $globalSemaphoreKey && $semaphoreCustomKey !== $systemSemaphoreKey) {
                // Stored key is a genuine per-account custom key (not the shared global/system key)
                $selectedProvider = 'semaphore';
                $activeApiKey = $semaphoreCustomKey;
                $apiKeySource = !empty($intData['nola_pro_api_key'])
                    ? 'integration.nola_pro_api_key'
                    : 'integration.semaphore_api_key';
                $usingCustomKey = true;
            } else {
                // No custom key, or stored key is the global/system pool key
                $selectedProvider = 'semaphore';
                $activeApiKey = $globalSemaphoreKey ?: $systemSemaphoreKey;
                $apiKeySource = $globalSemaphoreKey && $globalSemaphoreKey !== $systemSemaphoreKey
                    ? 'config.SEMAPHORE_GLOBAL_API_KEY'
                    : 'config.SEMAPHORE_API_KEY';
                $usingCustomKey = false;
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
        } elseif ($approvedSender !== '') {
            // PRIORITY: If the location has an explicit approved sender, always use it —
            // even when the automation template has hardcoded "NOLASMSPro" as the sendername.
            // This fixes the bug where automation payloads could bypass the registered sender ID.
            if ($selectedProvider === 'unisms' && $unismsSender !== '') {
                $sender = $unismsSender;
                $senderSource = 'integration.unisms_sender_id';
            } else {
                $sender = $approvedSender;
                $senderSource = 'integration.approved_sender_id';
            }
        } elseif (strcasecmp((string)$requestedSender, 'NOLASMSPro') === 0) {
            // No approved sender on this location + automation/user explicitly requested the
            // system default — honour it and route via the NOLA master key.
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
            } elseif (trim((string)$requestedSender) !== '') {
                $sender = trim((string)$requestedSender);
                $senderSource = 'request.sender';
            }
        } elseif ($usingCustomKey) {
            // PATH A: subaccount has their own API key — no approved sender set,
            // fall back to whatever the request specified.
            if (trim((string)$requestedSender) !== '') {
                $sender = trim((string)$requestedSender);
                $senderSource = 'request.sender';
            }
        } elseif (trim((string)$requestedSender) !== '' && in_array((string)$requestedSender, $masterSenders, true)) {
            // PATH C: no approved sender — only allow the requested sender if it is
            // on the global master whitelist (prevents arbitrary sender spoofing)
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

    public static function resolveStatusApiKey($db, string $locationId, string $providerName, ?string $systemSemaphoreKey, bool $isSystem = false, ?string $globalSemaphoreKey = null): array
    {
        $providerName = self::normalizeProvider($providerName);
        // Fallback: if global key not provided, use system key as the reference
        $globalKey = ($globalSemaphoreKey && $globalSemaphoreKey !== '') ? $globalSemaphoreKey : $systemSemaphoreKey;

        if ($isSystem) {
            return [
                'api_key' => $providerName === 'semaphore' ? $globalKey : null,
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
                        // If the stored key is the global or system pool key, return the live global key
                        // (avoids returning a stale copy from Firestore if the key was rotated)
                        if ($key === $globalKey || $key === $systemSemaphoreKey) {
                            return ['api_key' => $globalKey, 'source' => 'config.SEMAPHORE_GLOBAL_API_KEY'];
                        }
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
            'api_key' => $providerName === 'semaphore' ? $globalKey : null,
            'source' => $providerName === 'semaphore' ? 'config.SEMAPHORE_API_KEY' : 'admin_config.unisms_api_key',
        ];
    }

    public static function extractInboundDestinationSender(array $data): string
    {
        $candidates = [
            $data['recipient'] ?? null,
            $data['receiver'] ?? null,
            $data['destination'] ?? null,
            $data['sendername'] ?? null,
            $data['sender_name'] ?? null,
            $data['sender_id'] ?? null,
            $data['to'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string)$candidate);
            if ($value === '' || self::looksLikePhoneNumber($value)) {
                continue;
            }
            return $value;
        }

        return '';
    }

    /**
     * @return string|null Canonical HighLevel location id (without ghl_ prefix)
     */
    public static function resolveLocationByApprovedSender($db, string $senderName): ?string
    {
        $needle = strtolower(trim($senderName));
        if ($needle === '') {
            return null;
        }

        $cacheKey = 'inbound_sender_loc_' . md5($needle);
        try {
            require_once __DIR__ . '/../cache_helper.php';
            $cached = NolaCache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        } catch (\Throwable $e) {
            // Cache is optional.
        }

        $matches = [];

        try {
            foreach ($db->collection('integrations')->documents() as $doc) {
                if (!$doc->exists()) {
                    continue;
                }
                $data = $doc->data() ?: [];
                $approved = strtolower(trim((string)($data['approved_sender_id'] ?? '')));
                $unisms = strtolower(trim((string)($data['unisms_sender_id'] ?? '')));
                if ($approved !== $needle && $unisms !== $needle) {
                    continue;
                }
                $loc = self::locationIdFromIntegrationDoc($doc->id(), $data);
                if ($loc !== '') {
                    $matches[$loc] = true;
                }
            }
        } catch (\Throwable $e) {
            error_log('[SenderResolver][inbound_sender] integrations scan failed: ' . $e->getMessage());
        }

        if (count($matches) !== 1) {
            try {
                foreach ($db->collection('sender_id_requests')->documents() as $doc) {
                    if (!$doc->exists()) {
                        continue;
                    }
                    $data = $doc->data() ?: [];
                    if (strtolower((string)($data['status'] ?? '')) !== 'approved') {
                        continue;
                    }
                    $stored = strtolower(trim((string)(
                        $data['requested_id'] ?? $data['sender_id'] ?? $data['sender_name'] ?? ''
                    )));
                    if ($stored !== $needle) {
                        continue;
                    }
                    $loc = trim((string)($data['location_id'] ?? ''));
                    if ($loc !== '') {
                        $matches[$loc] = true;
                    }
                }
            } catch (\Throwable $e) {
                error_log('[SenderResolver][inbound_sender] sender_id_requests scan failed: ' . $e->getMessage());
            }
        }

        $locationIds = array_keys($matches);
        if (count($locationIds) !== 1) {
            if (count($locationIds) > 1) {
                error_log('[SenderResolver][inbound_sender] ambiguous sender=' . $senderName . ' locations=' . implode(',', $locationIds));
            }
            return null;
        }

        $locationId = $locationIds[0];
        try {
            require_once __DIR__ . '/../cache_helper.php';
            NolaCache::set($cacheKey, $locationId, 300);
        } catch (\Throwable $e) {
            // Cache is optional.
        }

        return $locationId;
    }

    /**
     * @return list<string>
     */
    public static function inboundPhoneLookupKeys(string $rawPhone): array
    {
        require_once __DIR__ . '/PhoneNormalizer.php';
        $keys = [];
        $raw = trim($rawPhone);
        if ($raw !== '') {
            $keys[$raw] = true;
        }
        $normalized = PhoneNormalizer::philippineMobile($raw);
        if ($normalized) {
            $keys[$normalized] = true;
            $keys[ltrim($normalized, '0')] = true;
            $keys['63' . ltrim($normalized, '0')] = true;
            $keys['+63' . ltrim($normalized, '0')] = true;
        }
        return array_keys($keys);
    }

    public static function looksLikePhoneNumber(string $value): bool
    {
        $digits = preg_replace('/\D/', '', $value);
        return $digits !== '' && strlen($digits) >= 10 && strlen($digits) <= 15 && !preg_match('/[a-zA-Z]/', $value);
    }

    private static function locationIdFromIntegrationDoc(string $docId, array $data): string
    {
        $fromField = trim((string)($data['location_id'] ?? ''));
        if ($fromField !== '') {
            return $fromField;
        }
        if (str_starts_with($docId, 'ghl_')) {
            return substr($docId, 4);
        }
        return $docId;
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
