<?php

require_once __DIR__ . '/SmsGatewayService.php';
require_once __DIR__ . '/providers/SemaphoreProvider.php';
require_once __DIR__ . '/providers/UniSmsProvider.php';

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
     * Fetch balance for a provider and API key with deduplication / memoization.
     */
    public function fetchBalance(string $provider, ?string $apiKey): array
    {
        $providerKey = self::normalizeProvider($provider);
        $cleanApiKey = trim((string)($apiKey ?? ''));

        if ($cleanApiKey === '') {
            return [
                'status'  => 'inactive',
                'credits' => 0,
                'error'   => 'Missing API key',
            ];
        }

        $cacheId = $providerKey . ':' . md5($cleanApiKey);
        if (isset($this->balanceCache[$cacheId])) {
            return $this->balanceCache[$cacheId];
        }

        $result = ['status' => 'inactive', 'credits' => 0, 'error' => null];

        try {
            if ($providerKey === 'unisms') {
                $uni = new UniSmsProvider(array_merge($this->config, ['UNISMS_API_KEY' => $cleanApiKey]));
                $check = $uni->checkAccount();
                $result['status']  = $check['status'] ?? 'active';
                $result['credits'] = (int)($check['credits'] ?? 0);
            } else {
                $sem = new SemaphoreProvider(array_merge($this->config, ['SEMAPHORE_API_KEY' => $cleanApiKey]));
                $check = $sem->checkAccount();
                $result['status']  = $check['status'] ?? 'active';
                $result['credits'] = (int)($check['credits'] ?? 0);
            }
        } catch (\Throwable $e) {
            $result['status'] = 'error';
            $result['error']  = $e->getMessage();
            error_log("[SemaphoreBalanceFetcher] Balance check failed ({$providerKey}): " . $e->getMessage());
        }

        $this->balanceCache[$cacheId] = $result;
        return $result;
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
        $semTotalCredits  = 0;
        $semConnectedAccs = 0;
        foreach ($semKeys as $key) {
            $bal = $this->fetchBalance('semaphore', $key);
            if ($bal['status'] === 'active') {
                $semTotalCredits += max(0, (int)$bal['credits']);
                $semConnectedAccs++;
            }
        }

        // 4. Calculate UniSMS totals
        $uniTotalCredits  = 0;
        $uniConnectedAccs = 0;
        foreach ($uniKeys as $key) {
            $bal = $this->fetchBalance('unisms', $key);
            if ($bal['status'] === 'active') {
                $uniTotalCredits += max(0, (int)$bal['credits']);
                $uniConnectedAccs++;
            }
        }

        return [
            'semaphore' => [
                'name'               => 'Semaphore',
                'status'             => $semConnectedAccs > 0 ? 'active' : 'inactive',
                'credits'            => $semTotalCredits,
                'total_credits'      => $semTotalCredits,
                'total_accounts'     => $semConnectedAccs,
                'connected_accounts' => $semConnectedAccs,
            ],
            'unisms' => [
                'name'               => 'UniSMS',
                'status'             => $uniConnectedAccs > 0 ? 'active' : 'inactive',
                'credits'            => $uniTotalCredits,
                'total_credits'      => $uniTotalCredits,
                'total_accounts'     => $uniConnectedAccs,
                'connected_accounts' => $uniConnectedAccs,
            ]
        ];
    }

    /**
     * Enrich user subaccount record with live provider balance and display fields.
     */
    public function enrichSubaccount(array $userRecord, ?array $intData): array
    {
        $resolved = $this->resolveProviderAndKey($intData);
        $balanceInfo = $this->fetchBalance($resolved['provider'], $resolved['api_key']);

        $userRecord['sms_provider']            = $resolved['provider_label'];
        $userRecord['provider']                = $resolved['provider'];
        $userRecord['provider_credit_balance'] = (int)($balanceInfo['credits'] ?? 0);
        $userRecord['provider_balance']        = (int)($balanceInfo['credits'] ?? 0);
        $userRecord['provider_status']         = $balanceInfo['status'] ?? 'inactive';

        return $userRecord;
    }
}
