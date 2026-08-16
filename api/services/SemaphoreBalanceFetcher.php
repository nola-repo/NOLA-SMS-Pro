<?php

require_once __DIR__ . '/SmsGatewayService.php';
require_once __DIR__ . '/providers/SemaphoreProvider.php';
require_once __DIR__ . '/providers/UniSmsProvider.php';
// Ensure NolaCache is available when this class is instantiated outside a full request context.
if (!class_exists('NolaCache', false)) {
    require_once __DIR__ . '/../cache_helper.php';
}

class SemaphoreBalanceFetcher
{
    private array $config;
    private array $balanceCache = [];

    public function __construct(?array $config = null)
    {
        if ($config !== null) {
            $this->config = $config;
        } else {
            $this->config = $this->loadConfig();
        }
    }

    private function loadConfig(): array
    {
        $systemConfig = require __DIR__ . '/../webhook/config.php';
        $resolved = [
            'SEMAPHORE_API_KEY' => $systemConfig['SEMAPHORE_API_KEY'] ?? '',
            'SEMAPHORE_URL'     => $systemConfig['SEMAPHORE_URL'] ?? 'https://api.semaphore.co/api/v4/messages',
            'UNISMS_API_KEY'    => $systemConfig['UNISMS_API_KEY'] ?? '',
            'UNISMS_SENDER_ID'  => $systemConfig['UNISMS_SENDER_ID'] ?? '',
            'UNISMS_ENDPOINT'   => $systemConfig['UNISMS_ENDPOINT'] ?? 'https://unismsapi.com/api',
            'active_provider'   => 'semaphore',
        ];

        try {
            $db = get_firestore();
            $docRef = $db->collection('admin_config')->document('sms_provider');
            $snap = $docRef->snapshot();
            if ($snap->exists()) {
                $data = $snap->data();
                if (!empty($data['active_provider'])) {
                    $resolved['active_provider'] = $data['active_provider'];
                }
                if (!empty($data['semaphore_api_key'])) {
                    $resolved['SEMAPHORE_API_KEY'] = $data['semaphore_api_key'];
                }
                if (!empty($data['nola_pro_api_key'])) {
                    $resolved['SEMAPHORE_API_KEY'] = $data['nola_pro_api_key'];
                }
                if (!empty($data['unisms_api_key'])) {
                    $resolved['UNISMS_API_KEY'] = $data['unisms_api_key'];
                }
                if (!empty($data['unisms_sender_id'])) {
                    $resolved['UNISMS_SENDER_ID'] = $data['unisms_sender_id'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[SemaphoreBalanceFetcher] Admin config load warning: ' . $e->getMessage());
        }

        return $resolved;
    }

    /**
     * Normalize provider key to 'semaphore', 'unisms', or fallback.
     */
    public static function normalizeProvider(?string $value): string
    {
        $p = strtolower(trim((string)($value ?? 'semaphore')));
        if (in_array($p, ['unisms', 'unisms_custom'], true)) {
            return 'unisms';
        }
        if (in_array($p, ['semaphore', 'semaphore_custom'], true)) {
            return 'semaphore';
        }
        return 'semaphore';
    }

    /**
     * Resolve provider and active API key for a subaccount given its integration document.
     */
    public function resolveProviderAndKey(?array $intData): array
    {
        $intData = $intData ?? [];
        $rawPref = $intData['approved_provider'] ?? $intData['provider'] ?? $intData['provider_preference'] ?? 'system';
        $providerKey = self::normalizeProvider($rawPref);

        // System fallback if raw preference is system
        if (in_array(strtolower(trim((string)$rawPref)), ['system', 'auto_failover', ''], true)) {
            $activeSysProvider = self::normalizeProvider($this->config['active_provider'] ?? 'semaphore');
            $providerKey = $activeSysProvider;
        }

        if ($providerKey === 'unisms') {
            $customKey = trim((string)($intData['unisms_api_key'] ?? ''));
            $apiKey = $customKey !== '' ? $customKey : trim((string)($this->config['UNISMS_API_KEY'] ?? ''));
            return [
                'provider'       => 'unisms',
                'provider_label' => 'UniSMS',
                'api_key'        => $apiKey,
                'is_custom_key'  => $customKey !== '',
            ];
        }

        // Default to Semaphore
        $customKey = trim((string)($intData['nola_pro_api_key'] ?? ($intData['semaphore_api_key'] ?? '')));
        $apiKey = $customKey !== '' ? $customKey : trim((string)($this->config['SEMAPHORE_API_KEY'] ?? ''));
        return [
            'provider'       => 'semaphore',
            'provider_label' => 'Semaphore',
            'api_key'        => $apiKey,
            'is_custom_key'  => $customKey !== '',
        ];
    }

    /**
     * Fetch the live credit balance for a given provider + API key.
     *
     * Fallback chain (v2 — 2026-08-12):
     *   Tier 1 — Live API call via checkAccount()
     *   Tier 2 — Redis short-TTL cache (prov_bal_<provider>_<hash>, 15 min)
     *   Tier 3 — Firestore last-known-good (admin_config/provider_balance_lkg)
     *   Tier 4 — Per-subaccount Firestore provider_credit_balance field
     *
     * Key design decision: a `timeout_failure` result from checkAccount() is
     * NOT treated as confirmed inactivity.  The API timed out — we do not know
     * the real balance.  We fall through to the cached / persisted value rather
     * than propagating 0 credits to the admin dashboard.
     *
     * @param string      $provider     'semaphore' | 'unisms'
     * @param string|null $apiKey       The API key to check
     * @param array|null  $fallbackData Per-subaccount Firestore doc (Tier 4)
     * @return array{status:string, credits:int, error:?string, fetched_via:string}
     */
    public function fetchBalance(string $provider, ?string $apiKey, ?array $fallbackData = null): array
    {
        $providerKey = self::normalizeProvider($provider);
        $cleanApiKey = trim((string)($apiKey ?? ''));

        if ($cleanApiKey === '') {
            return [
                'status'      => 'inactive',
                'credits'     => 0,
                'error'       => 'Missing API key',
                'fetched_via' => 'none',
            ];
        }

        // ── In-process memoization (deduplication within a single request) ───
        $cacheId = $providerKey . ':' . md5($cleanApiKey);
        if (isset($this->balanceCache[$cacheId])) {
            return $this->balanceCache[$cacheId];
        }

        // ── Tier 2: Redis short-TTL cache ────────────────────────────────────
        $redisCacheKey = "prov_bal_{$providerKey}_" . md5($cleanApiKey);
        $redisResult   = NolaCache::get($redisCacheKey);

        $result = ['status' => 'inactive', 'credits' => 0, 'error' => null, 'fetched_via' => 'none'];

        try {
            // ── Tier 1: Live API call ─────────────────────────────────────────
            if ($providerKey === 'unisms') {
                $uni   = new UniSmsProvider(array_merge($this->config, ['UNISMS_API_KEY' => $cleanApiKey]));
                $check = $uni->checkAccount();
            } else {
                $sem   = new SemaphoreProvider(array_merge($this->config, ['SEMAPHORE_API_KEY' => $cleanApiKey]));
                $check = $sem->checkAccount();
            }

            $isTimeout = !empty($check['timeout_failure']);
            $isActive  = ($check['status'] ?? '') === 'active';

            if ($isActive) {
                // ── Tier 1 success ────────────────────────────────────────────
                $result = [
                    'status'      => 'active',
                    'credits'     => (int) ($check['credits'] ?? 0),
                    'error'       => null,
                    'fetched_via' => 'live_api',
                ];
                // Warm Tier 2 (Redis) and Tier 3 (Firestore) with good data
                NolaCache::set($redisCacheKey, $result, 900);
                $this->persistLastKnownGood($providerKey, $cleanApiKey, $result['credits']);

            } elseif ($isTimeout && $redisResult !== null) {
                // ── Tier 2: Redis has stale-but-valid data after a timeout ────
                $result = array_merge($redisResult, ['fetched_via' => 'redis_cache_after_timeout']);

            } elseif ($isTimeout) {
                // ── Tier 3: Redis cold — try Firestore last-known-good ────────
                $lkg = $this->readLastKnownGood($providerKey, $cleanApiKey);
                if ($lkg !== null) {
                    $result = [
                        'status'      => 'active',
                        'credits'     => $lkg['credits'],
                        'error'       => null,
                        'fetched_via' => 'firestore_lkg',
                    ];
                    // Re-warm Redis so the next request doesn't hit Firestore again
                    NolaCache::set($redisCacheKey, $result, 300);
                } elseif (!empty($fallbackData['provider_credit_balance'])) {
                    // ── Tier 4: Per-subaccount Firestore field ────────────────
                    $result = [
                        'status'      => 'active',
                        'credits'     => (int) $fallbackData['provider_credit_balance'],
                        'error'       => null,
                        'fetched_via' => 'subaccount_firestore_field',
                    ];
                    NolaCache::set($redisCacheKey, $result, 300);
                } else {
                    // All fallbacks exhausted — surface the timeout as an error
                    $result = [
                        'status'      => 'inactive',
                        'credits'     => 0,
                        'error'       => $check['error'] ?? 'API timeout — no cached value available',
                        'fetched_via' => 'none',
                    ];
                }
            } elseif ($redisResult !== null) {
                // ── Non-timeout non-active (e.g. HTTP 429 rate limit): use Redis cache ───
                $result = array_merge($redisResult, ['fetched_via' => 'redis_cache']);
            } else {
                // ── No Redis cache — try Firestore LKG (handles HTTP 429 as well) ────────
                $lkg = $this->readLastKnownGood($providerKey, $cleanApiKey);
                if ($lkg !== null) {
                    $result = [
                        'status'      => 'active',
                        'credits'     => $lkg['credits'],
                        'error'       => null,
                        'fetched_via' => 'firestore_lkg',
                    ];
                    NolaCache::set($redisCacheKey, $result, 300);
                } elseif (!empty($fallbackData['provider_credit_balance'])) {
                    // ── Tier 4: Per-subaccount Firestore field ─────────────────────────────
                    $result = [
                        'status'      => 'active',
                        'credits'     => (int) $fallbackData['provider_credit_balance'],
                        'error'       => null,
                        'fetched_via' => 'subaccount_firestore_field',
                    ];
                    NolaCache::set($redisCacheKey, $result, 900);
                } else {
                    $result = [
                        'status'      => $check['status'] ?? 'inactive',
                        'credits'     => (int) ($check['credits'] ?? 0),
                        'error'       => $check['error'] ?? null,
                        'fetched_via' => 'none',
                    ];
                }
            }

        } catch (\Throwable $e) {
            error_log("[SemaphoreBalanceFetcher] fetchBalance exception ({$providerKey}): " . $e->getMessage());

            if ($redisResult !== null) {
                $result = array_merge($redisResult, ['fetched_via' => 'redis_cache_after_exception']);
            } elseif (!empty($fallbackData['provider_credit_balance'])) {
                $result = [
                    'status'      => 'active',
                    'credits'     => (int) $fallbackData['provider_credit_balance'],
                    'error'       => null,
                    'fetched_via' => 'subaccount_firestore_field',
                ];
            } else {
                $result = [
                    'status'      => 'error',
                    'credits'     => 0,
                    'error'       => $e->getMessage(),
                    'fetched_via' => 'none',
                ];
            }
        }

        $this->balanceCache[$cacheId] = $result;
        return $result;
    }

    /**
     * Persist a confirmed live balance as the last-known-good value in Firestore.
     * Stored under admin_config/provider_balance_lkg as a map keyed by
     * "{provider}_{keyHash}" so multiple API keys can coexist.
     *
     * Written asynchronously-ish: failures are logged but never thrown.
     */
    private function persistLastKnownGood(string $providerKey, string $apiKey, int $credits): void
    {
        try {
            $db = get_firestore();
            $field = $providerKey . '_' . md5($apiKey);
            $db->collection('admin_config')->document('provider_balance_lkg')->set([
                $field => [
                    'credits'    => $credits,
                    'updated_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
                ],
            ], ['merge' => true]);
        } catch (\Throwable $e) {
            error_log("[SemaphoreBalanceFetcher] persistLastKnownGood failed ({$providerKey}): " . $e->getMessage());
        }
    }

    /**
     * Read the Firestore last-known-good balance for a provider + key.
     * Returns null if no value has ever been persisted (first-ever run).
     *
     * @return array{credits:int, updated_at:string}|null
     */
    private function readLastKnownGood(string $providerKey, string $apiKey): ?array
    {
        try {
            $db   = get_firestore();
            $snap = $db->collection('admin_config')->document('provider_balance_lkg')->snapshot();
            if (!$snap->exists()) {
                return null;
            }
            $field = $providerKey . '_' . md5($apiKey);
            $data  = $snap->data();
            if (!isset($data[$field]['credits'])) {
                return null;
            }
            return [
                'credits'    => (int) $data[$field]['credits'],
                'updated_at' => (string) ($data[$field]['updated_at'] ?? ''),
            ];
        } catch (\Throwable $e) {
            error_log("[SemaphoreBalanceFetcher] readLastKnownGood failed ({$providerKey}): " . $e->getMessage());
            return null;
        }
    }

    /**
     * Gather dashboard summary across all connected accounts.
     */
    public function getDashboardSummary($db): array
    {
        $semKeys = [];
        $uniKeys = [];

        // 1. Add system default keys
        $sysSemKey = trim((string)($this->config['SEMAPHORE_API_KEY'] ?? ''));
        if ($sysSemKey !== '') {
            $semKeys[] = $sysSemKey;
        }

        $sysUniKey = trim((string)($this->config['UNISMS_API_KEY'] ?? ''));
        if ($sysUniKey !== '') {
            $uniKeys[] = $sysUniKey;
        }

        // 2. Discover custom keys from integrations collection
        try {
            $integrationsSnap = $db->collection('integrations')->limit(500)->documents();
            foreach ($integrationsSnap as $doc) {
                if (!$doc->exists()) continue;
                $data = $doc->data();

                $semCustom = trim((string)($data['nola_pro_api_key'] ?? ($data['semaphore_api_key'] ?? '')));
                if ($semCustom !== '') {
                    $semKeys[] = $semCustom;
                }

                $uniCustom = trim((string)($data['unisms_api_key'] ?? ''));
                if ($uniCustom !== '') {
                    $uniKeys[] = $uniCustom;
                }
            }
        } catch (\Throwable $e) {
            error_log('[SemaphoreBalanceFetcher] Integrations query error: ' . $e->getMessage());
        }

        $semKeys = array_values(array_unique($semKeys));
        $uniKeys = array_values(array_unique($uniKeys));

        // 3. Calculate Semaphore totals
        // Pacing: Semaphore's API rate-limits concurrent requests (HTTP 429).
        // Sleep 300ms between each key to stay within the rate limit when multiple
        // custom API keys are registered on the platform.
        $semTotalCredits  = 0;
        $semConnectedAccs = 0;
        $semFetchSources  = [];
        foreach ($semKeys as $index => $key) {
            if ($index > 0) {
                usleep(300000); // 300ms pacing between Semaphore API calls
            }
            $bal = $this->fetchBalance('semaphore', $key);
            if ($bal['status'] === 'active') {
                $semTotalCredits += max(0, (int)$bal['credits']);
                $semConnectedAccs++;
                // Track how this balance was sourced for data-quality metadata
                $semFetchSources[] = $bal['fetched_via'] ?? 'none';
            }
        }

        // 4. Calculate UniSMS totals
        $uniTotalCredits  = 0;
        $uniConnectedAccs = 0;
        $uniFetchSources  = [];
        foreach ($uniKeys as $key) {
            $bal = $this->fetchBalance('unisms', $key);
            if ($bal['status'] === 'active') {
                $uniTotalCredits += max(0, (int)$bal['credits']);
                $uniConnectedAccs++;
                $uniFetchSources[] = $bal['fetched_via'] ?? 'none';
            }
        }

        // Derive dominant fetched_via for each provider:
        // Prefer 'live_api' if any key was live; otherwise use the first source seen.
        $semFetchedVia = in_array('live_api', $semFetchSources, true) ? 'live_api'
            : ($semFetchSources[0] ?? 'none');
        $uniFetchedVia = in_array('live_api', $uniFetchSources, true) ? 'live_api'
            : ($uniFetchSources[0] ?? 'none');

        return [
            'semaphore' => [
                'name'               => 'Semaphore',
                'status'             => $semConnectedAccs > 0 ? 'active' : 'inactive',
                'credits'            => $semTotalCredits,
                'total_credits'      => $semTotalCredits,
                // total_accounts = all unique Semaphore keys discovered (system + custom)
                // connected_accounts = only those whose API call returned a live balance
                'total_accounts'     => count($semKeys),
                'connected_accounts' => $semConnectedAccs,
                // Data-quality: how the aggregated balance was sourced
                'fetched_via'        => $semFetchedVia,
            ],
            'unisms' => [
                'name'               => 'UniSMS',
                'status'             => $uniConnectedAccs > 0 ? 'active' : 'inactive',
                'credits'            => $uniTotalCredits,
                'total_credits'      => $uniTotalCredits,
                'total_accounts'     => count($uniKeys),
                'connected_accounts' => $uniConnectedAccs,
                'fetched_via'        => $uniFetchedVia,
            ]
        ];
    }

    /**
     * Enrich user subaccount record with live provider balance and display fields.
     */
    public function enrichSubaccount(array $userRecord, ?array $intData): array
    {
        $resolved = $this->resolveProviderAndKey($intData);
        $balanceInfo = $this->fetchBalance($resolved['provider'], $resolved['api_key'], $intData);

        $userRecord['sms_provider']            = $resolved['provider_label'];
        $userRecord['provider']                = $resolved['provider'];
        $userRecord['provider_credit_balance'] = (int)($balanceInfo['credits'] ?? 0);
        $userRecord['provider_balance']        = (int)($balanceInfo['credits'] ?? 0);
        $userRecord['provider_status']         = $balanceInfo['status'] ?? 'inactive';
        // Indicates whether the balance was fetched from the account's own custom API key
        // or the platform-wide system default key. Used by the frontend to label
        // "Custom Key" vs "System Key" in the All Subaccounts table.
        $userRecord['provider_balance_source'] = !empty($resolved['is_custom_key']) ? 'custom' : 'system';

        return $userRecord;
    }
}
