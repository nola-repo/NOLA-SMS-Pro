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
            'UNISMS_TIMEOUT_SECONDS' => (int)($systemConfig['UNISMS_TIMEOUT_SECONDS'] ?? 15),
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
                if (!empty($data['unisms_timeout_seconds'])) {
                    $resolvedConfig['UNISMS_TIMEOUT_SECONDS'] = (int)$data['unisms_timeout_seconds'];
                }
                $resolvedConfig['failover_timeout_seconds'] = (int)($data['failover_timeout_seconds'] ?? 8);
                $resolvedConfig['failover_log_enabled'] = (bool)($data['failover_log_enabled'] ?? true);
                $resolvedConfig['resilience_engine_enabled'] = (bool)($data['resilience_engine_enabled'] ?? true);
                $resolvedConfig['resilience_circuit_breaker_enabled'] = (bool)($data['resilience_circuit_breaker_enabled'] ?? true);
            }
        } catch (\Throwable $e) {
            error_log("[SmsGatewayService] Config load error, using config.php fallbacks: " . $e->getMessage());
        }

        return $resolvedConfig;
    }

    /**
     * Checks sliding 60-second window in cache for provider failure rate.
     * If failure rate > 50% (min 5 requests), returns true (Circuit Breaker OPEN).
     */
    private function isCircuitBreakerOpen(string $provider): bool
    {
        if (!($this->config['resilience_circuit_breaker_enabled'] ?? true)) {
            return false;
        }

        require_once __DIR__ . '/../cache_helper.php';
        $key = "cb_metrics_" . strtolower($provider);
        $metrics = NolaCache::get($key) ?: ['total' => 0, 'fails' => 0, 'ts' => time()];

        // Reset metrics window every 60 seconds
        if ((time() - ($metrics['ts'] ?? 0)) > 60) {
            return false;
        }

        $total = $metrics['total'] ?? 0;
        $fails = $metrics['fails'] ?? 0;

        if ($total >= 5 && ($fails / $total) > 0.5) {
            error_log(json_encode([
                'event'                  => 'circuit_breaker_triggered',
                'provider'               => $provider,
                'total_requests_60s'     => $total,
                'failed_requests_60s'    => $fails,
                'failure_rate_percentage'=> round(($fails / $total) * 100, 1),
                'circuit_status'         => 'OPEN'
            ]));
            return true;
        }

        return false;
    }

    private function recordProviderMetric(string $provider, bool $isFailure): void
    {
        try {
            require_once __DIR__ . '/../cache_helper.php';
            $key = "cb_metrics_" . strtolower($provider);
            $metrics = NolaCache::get($key) ?: ['total' => 0, 'fails' => 0, 'ts' => time()];
            if ((time() - ($metrics['ts'] ?? 0)) > 60) {
                $metrics = ['total' => 0, 'fails' => 0, 'ts' => time()];
            }
            $metrics['total'] = ($metrics['total'] ?? 0) + 1;
            if ($isFailure) {
                $metrics['fails'] = ($metrics['fails'] ?? 0) + 1;
            }
            NolaCache::set($key, $metrics, 60);
        } catch (\Throwable $e) {
            // Ignore cache metric write errors
        }
    }

    /**
     * Checks whether a sender ID is compatible with UniSMS.
     * Custom Semaphore-only sender IDs (e.g. JNKRENTAL) return false to prevent brand override ("NOLA").
     */
    private function isSenderCompatibleWithUniSms(string $senderId): bool
    {
        $unismsSender = trim((string)($this->config['UNISMS_SENDER_ID'] ?? ''));
        $senderId     = trim($senderId);

        if ($senderId === '' || strcasecmp($senderId, 'NOLA') === 0 || strcasecmp($senderId, 'NOLASMSPro') === 0) {
            return true;
        }
        if ($unismsSender !== '' && strcasecmp($senderId, $unismsSender) === 0) {
            return true;
        }
        return false;
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
     * Sends messages through the active provider, handling auto-failover and Sender-ID Guard.
     *
     * @return array Standardized result items: [['message_id' => '...', 'status' => '...', 'recipient' => '...'], ...]
     */
    public function send(array $numbers, string $message, string $senderId, ?string $customApiKey = null, ?string $providerPreference = null): array
    {
        // 1. Resolve preferred/forced provider
        $providerName = $providerPreference ?: $this->activeProviderName;
        if ($providerName === 'system') {
            $providerName = $this->activeProviderName;
        }

        // 2. Dynamic Routing Override
        if ($customApiKey !== null) {
            if (in_array($providerName, ['unisms', 'unisms_custom'], true)) {
                $providerName = 'unisms';
            } elseif (in_array($providerName, ['semaphore', 'semaphore_custom'], true)) {
                $providerName = 'semaphore';
            } else {
                $providerName = str_starts_with(trim($customApiKey), 'sk_') ? 'unisms' : 'semaphore';
            }
        } else {
            $unismsSender = trim($this->config['UNISMS_SENDER_ID'] ?? '');
            if ($providerName === 'unisms' || ($unismsSender !== '' && strcasecmp(trim($senderId), $unismsSender) === 0)) {
                $providerName = 'unisms';
            } else {
                if ($providerName !== 'auto_failover') {
                    $providerName = 'semaphore';
                }
            }
        }

        if ($providerName !== 'auto_failover') {
            $prov = $this->getProviderInstance($providerName);
            try {
                $results = $prov->sendBulk($numbers, $message, $senderId, $customApiKey);
                $this->recordProviderMetric($providerName, false);
                return [
                    'provider' => $providerName,
                    'results'  => $results
                ];
            } catch (\Throwable $e) {
                $this->recordProviderMetric($providerName, true);
                throw $e;
            }
        }

        // 3. Handle Auto-Failover Flow with Circuit Breaker & Sender-ID Guard
        $primary  = new SemaphoreProvider($this->config);
        $fallback = new UniSmsProvider($this->config);

        $cbOpen = $this->isCircuitBreakerOpen('semaphore');
        $senderCompatible = $this->isSenderCompatibleWithUniSms($senderId);

        if ($cbOpen && !$senderCompatible) {
            // Circuit Breaker OPEN and sender is custom to Semaphore (e.g., JNKRENTAL).
            // Do NOT swap sender ID to "NOLA" on UniSMS! Log guard event and throw structured queue exception.
            error_log(json_encode([
                'event'               => 'sender_id_guard_triggered',
                'provider'            => 'semaphore',
                'sender_id'           => $senderId,
                'circuit_breaker'     => 'OPEN',
                'guard_action'        => 'blocked_unisms_swap_queued_for_retry',
                'reason'              => 'Semaphore degraded, custom sender_id preserved without provider overwrite'
            ]));
            throw new SemaphoreTimeoutException(
                "Semaphore network degraded (Circuit Breaker OPEN). Message queued for delayed retry under Sender ID {$senderId}.",
                'circuit_breaker_open',
                $senderId,
                $numbers[0] ?? ''
            );
        }

        try {
            $results = $primary->sendBulk($numbers, $message, $senderId, $customApiKey);
            $this->recordProviderMetric('semaphore', false);
            return [
                'provider' => 'semaphore',
                'results'  => $results
            ];
        } catch (\Throwable $e) {
            $this->recordProviderMetric('semaphore', true);
            $errMessage = $e->getMessage();
            $isNetworkError = (
                strpos(strtolower($errMessage), 'timeout') !== false ||
                strpos(strtolower($errMessage), 'http 5') !== false ||
                strpos(strtolower($errMessage), 'curl error') !== false
            );

            if ($isNetworkError) {
                // Sender-ID Guard Check: If sender is NOT compatible with UniSMS, do NOT swap sender ID!
                if (!$senderCompatible) {
                    error_log(json_encode([
                        'event'           => 'sender_id_guard_triggered',
                        'provider'        => 'semaphore',
                        'sender_id'       => $senderId,
                        'guard_action'    => 'prevented_unisms_failover',
                        'reason'          => 'Custom Semaphore sender ID cannot be substituted with default UniSMS sender'
                    ]));
                    // Preserve typed SmsProviderTimeoutException so callers (ghl_provider, send_sms)
                    // can queue the message for retry instead of marking it Failed immediately.
                    if ($e instanceof SmsProviderTimeoutException) {
                        throw $e;
                    }
                    throw new \Exception("Semaphore transient network error ({$errMessage}). Preserved custom Sender ID {$senderId} without UniSMS substitution.");
                }

                // Log incident to Firestore
                if ($this->config['failover_log_enabled']) {
                    try {
                        $now = new \DateTimeImmutable();
                        $ts = new Timestamp($now);
                        $this->db->collection('admin_logs')->document('failover_incidents')->collection('logs')->newDocument()->set([
                            'attempted_provider' => 'semaphore',
                            'reason'             => $errMessage,
                            'fallback'           => 'unisms',
                            'message_count'      => count($numbers),
                            'timestamp'          => $ts
                        ]);
                    } catch (\Throwable $logEx) {
                        error_log("[SmsGatewayService] Failover log write failed: " . $logEx->getMessage());
                    }
                }

                // Attempt sending via fallback (UniSMS) only when sender ID is compatible
                try {
                    $results = $fallback->sendBulk($numbers, $message, $senderId, $customApiKey);
                    $this->recordProviderMetric('unisms', false);
                    return [
                        'provider' => 'unisms',
                        'results'  => $results
                    ];
                } catch (\Throwable $fbEx) {
                    $this->recordProviderMetric('unisms', true);
                    // If fallback or original error was a typed timeout, preserve it so the
                    // retry queue mechanism (ghl_provider.php) can catch it correctly.
                    if ($fbEx instanceof SmsProviderTimeoutException) {
                        throw $fbEx;
                    }
                    if ($e instanceof SmsProviderTimeoutException) {
                        throw $e;
                    }
                    throw new \Exception("Primary send failed ({$errMessage}) and fallback send failed: " . $fbEx->getMessage());
                }
            } else {
                throw $e;
            }
        }
    }
}
