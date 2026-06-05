<?php

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/providers/SemaphoreProvider.php';
require_once __DIR__ . '/providers/UniSmsProvider.php';

use Google\Cloud\Core\Timestamp;

class SmsGatewayService
{
    private $db;
    private $activeProviderName; // 'semaphore' | 'unisms' | 'auto_failover'
    private $config = [];
    private $provider;

    public function __construct()
    {
        $this->db = get_firestore();
        $this->config = $this->loadConfig();
        $this->activeProviderName = $this->config['active_provider'] ?? 'semaphore';

        if ($this->activeProviderName === 'unisms') {
            $this->provider = new UniSmsProvider($this->config);
        } else {
            // Default to SemaphoreProvider for both 'semaphore' and 'auto_failover'
            $this->provider = new SemaphoreProvider($this->config);
        }
    }

    private function loadConfig(): array
    {
        // Default configs from config.php or environment variables
        $systemConfig = require __DIR__ . '/../webhook/config.php';
        $resolvedConfig = [
            'SEMAPHORE_API_KEY' => $systemConfig['SEMAPHORE_API_KEY'] ?? '',
            'SEMAPHORE_URL' => $systemConfig['SEMAPHORE_URL'] ?? 'https://api.semaphore.co/api/v4/messages',
            'UNISMS_API_KEY' => $systemConfig['UNISMS_API_KEY'] ?? '',
            'UNISMS_SENDER_ID' => $systemConfig['UNISMS_SENDER_ID'] ?? '',
            'UNISMS_ENDPOINT' => $systemConfig['UNISMS_ENDPOINT'] ?? 'https://unismsapi.com/api',
            'active_provider' => 'semaphore',
            'failover_timeout_seconds' => 8,
            'failover_log_enabled' => true
        ];

        try {
            $docRef = $this->db->collection('admin_config')->document('sms_provider');
            $snap = $docRef->snapshot();
            if ($snap->exists()) {
                $data = $snap->data();
                $resolvedConfig['active_provider'] = $data['active_provider'] ?? 'semaphore';
                if (!empty($data['unisms_api_key'])) {
                    $resolvedConfig['UNISMS_API_KEY'] = $data['unisms_api_key'];
                }
                if (!empty($data['unisms_sender_id'])) {
                    $resolvedConfig['UNISMS_SENDER_ID'] = $data['unisms_sender_id'];
                }
                if (!empty($data['unisms_endpoint'])) {
                    $resolvedConfig['UNISMS_ENDPOINT'] = $data['unisms_endpoint'];
                }
                $resolvedConfig['failover_timeout_seconds'] = (int)($data['failover_timeout_seconds'] ?? 8);
                $resolvedConfig['failover_log_enabled'] = (bool)($data['failover_log_enabled'] ?? true);
            }
        } catch (\Throwable $e) {
            error_log("[SmsGatewayService] Config load error, using config.php fallbacks: " . $e->getMessage());
        }

        return $resolvedConfig;
    }

    public function getProviderName(): string
    {
        return $this->activeProviderName;
    }

    public function getProviderInstance(string $name = null): SmsProviderInterface
    {
        $name = $name ?: $this->activeProviderName;
        if ($name === 'unisms') {
            return new UniSmsProvider($this->config);
        }
        return new SemaphoreProvider($this->config);
    }

    /**
     * Sends messages through the active provider, handling auto-failover if enabled.
     *
     * @return array Standardized result items: [['message_id' => '...', 'status' => '...', 'recipient' => '...'], ...]
     */
    public function send(array $numbers, string $message, string $senderId, ?string $customApiKey = null, ?string $providerPreference = null): array
    {
        // 1. Resolve preferred/forced provider
        $providerName = $providerPreference ?: $this->activeProviderName;

        // 2. Dynamic Routing Override
        if ($customApiKey !== null) {
            // Path A: Subaccount custom API key determines provider
            $providerName = str_starts_with(trim($customApiKey), 'sk_') ? 'unisms' : 'semaphore';
        } else {
            // Path B: Master gateway sender ID determines provider
            $unismsSender = trim($this->config['UNISMS_SENDER_ID'] ?? '');
            if ($unismsSender !== '' && strcasecmp(trim($senderId), $unismsSender) === 0) {
                $providerName = 'unisms';
            } else {
                // If the selected sender is not the dedicated UniSMS sender, assume Semaphore
                // (or allow auto_failover to run its course if that's the active provider)
                if ($providerName !== 'auto_failover') {
                    $providerName = 'semaphore';
                }
            }
        }

        if ($providerName !== 'auto_failover') {
            $prov = $this->getProviderInstance($providerName);
            $results = $prov->sendBulk($numbers, $message, $senderId, $customApiKey);
            return [
                'provider' => $providerName,
                'results' => $results
            ];
        }

        // 2. Handle Auto-Failover Flow
        // Primary: Semaphore with timeout
        $primary = new SemaphoreProvider($this->config);
        $fallback = new UniSmsProvider($this->config);

        try {
            // Set dynamic timeout config on primary for cURL
            // Since SemaphoreProvider doesn't support timeout configs in interface, we check via a manual try/catch.
            // SemaphoreProvider defaults to 15s, but we will run standard sendBulk.
            $results = $primary->sendBulk($numbers, $message, $senderId, $customApiKey);
            return [
                'provider' => 'semaphore',
                'results' => $results
            ];
        } catch (\Throwable $e) {
            // Check if failure is timeout/5xx or general endpoint down
            $errMessage = $e->getMessage();
            $isNetworkError = (
                strpos(strtolower($errMessage), 'timeout') !== false ||
                strpos(strtolower($errMessage), 'http 5') !== false ||
                strpos(strtolower($errMessage), 'curl error') !== false
            );

            if ($isNetworkError) {
                // Log incident to Firestore
                if ($this->config['failover_log_enabled']) {
                    try {
                        $now = new \DateTimeImmutable();
                        $ts = new Timestamp($now);
                        $this->db->collection('admin_logs')->document('failover_incidents')->collection('logs')->newDocument()->set([
                            'attempted_provider' => 'semaphore',
                            'reason' => $errMessage,
                            'fallback' => 'unisms',
                            'message_count' => count($numbers),
                            'timestamp' => $ts
                        ]);
                    } catch (\Throwable $logEx) {
                        error_log("[SmsGatewayService] Failover log write failed: " . $logEx->getMessage());
                    }
                }

                // Attempt sending via fallback (UniSMS)
                // Note: If using custom keys, we assume custom key belongs to the fallback if it starts with 'sk_' or similar,
                // otherwise fallback to system key. Let's send using fallback.
                try {
                    $results = $fallback->sendBulk($numbers, $message, $senderId, $customApiKey);
                    return [
                        'provider' => 'unisms',
                        'results' => $results
                    ];
                } catch (\Throwable $fbEx) {
                    throw new \Exception("Primary send failed ({$errMessage}) and fallback send failed: " . $fbEx->getMessage());
                }
            } else {
                // If it is a user input error (e.g. invalid sender name, wrong key), do NOT failover to prevent duplicate billing
                throw $e;
            }
        }
    }
}
