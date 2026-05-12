<?php

/**
 * Canonical GHL OAuth token resolution for ghl_tokens/{id}.
 *
 * Read order:
 * 1. Canonical document ghl_tokens/{registryKey} (registryKey = location ID or company ID per caller).
 * 2. Legacy fallback: query where location_id == registryKey (wrong doc ID migration path).
 *    When legacy data is found, merge into the canonical doc and log.
 * 3. Agency / company-backed location flows (locationToken exchange) — unchanged from legacy GhlClient.
 */

final class GhlOAuthRefreshException extends \RuntimeException
{
    public const REASON_INVALID_GRANT = 'invalid_grant';
    public const REASON_TRANSIENT = 'transient';
    public const REASON_MISSING_REFRESH = 'missing_refresh';
    public const REASON_LOCK_ACTIVE = 'lock_active';
    public const REASON_OTHER = 'other';

    /** @var string One of REASON_* */
    private string $reasonCode;

    private bool $canonicalDocExists;
    private bool $hadRefreshToken;
    private bool $refreshAttemptedWithClientCreds;
    private bool $activeLockMayResolve;

    public function __construct(
        string $message,
        string $reasonCode,
        bool $canonicalDocExists = false,
        bool $hadRefreshToken = false,
        bool $refreshAttemptedWithClientCreds = false,
        bool $activeLockMayResolve = false,
        int $code = 0,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
        $this->reasonCode = $reasonCode;
        $this->canonicalDocExists = $canonicalDocExists;
        $this->hadRefreshToken = $hadRefreshToken;
        $this->refreshAttemptedWithClientCreds = $refreshAttemptedWithClientCreds;
        $this->activeLockMayResolve = $activeLockMayResolve;
    }

    public function getReasonCode(): string
    {
        return $this->reasonCode;
    }

    /**
     * Only true when the backend should return requires_reconnect: true (401 contract).
     */
    public function shouldPromptReconnect(): bool
    {
        if ($this->activeLockMayResolve) {
            return false;
        }
        if (!$this->canonicalDocExists || !$this->hadRefreshToken || !$this->refreshAttemptedWithClientCreds) {
            return false;
        }
        return $this->reasonCode === self::REASON_INVALID_GRANT;
    }
}

final class GhlTokenRefreshLock
{
    private const COLLECTION = 'ghl_oauth_refresh_locks';
    private const TTL_SEC = 75;
    private const WAIT_SLICE_US = 250000;
    private const MAX_WAIT_SEC = 45;

    /**
     * Try to acquire the lock in a transaction. Returns false if another holder has a fresh lock.
     *
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     */
    public static function tryAcquire($db, string $lockDocId): bool
    {
        $ref = $db->collection(self::COLLECTION)->document(self::sanitizeDocId($lockDocId));
        $now = time();
        $acquired = false;

        try {
            $db->runTransaction(function ($transaction) use ($ref, $now, &$acquired) {
                $snap = $transaction->snapshot($ref);
                if ($snap->exists()) {
                    $until = (int) ($snap->data()['locked_until'] ?? 0);
                    if ($until > $now) {
                        return;
                    }
                }
                $transaction->set($ref, [
                    'locked_until' => $now + self::TTL_SEC,
                    'updated_at'   => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
                ]);
                $acquired = true;
            });
        } catch (\Throwable $e) {
            error_log('[GHL_TOKEN_LOCK] transaction failed: ' . $e->getMessage());
            $acquired = false;
        }

        return $acquired;
    }

    public static function release($db, string $lockDocId): void
    {
        try {
            $db->collection(self::COLLECTION)->document(self::sanitizeDocId($lockDocId))->delete();
        } catch (\Throwable $e) {
            error_log('[GHL_TOKEN_LOCK] release failed: ' . $e->getMessage());
        }
    }

    /**
     * Wait for another in-flight refresh to update Firestore, then return reloaded integration or null.
     *
     * @return array|null
     */
    public static function waitAndReloadIntegration($db, string $registryKey)
    {
        $deadline = microtime(true) + self::MAX_WAIT_SEC;
        while (microtime(true) < $deadline) {
            usleep(self::WAIT_SLICE_US);
            $data = GhlTokenProvider::loadIntegration($db, $registryKey);
            if ($data && self::integrationHasFreshAccess($data)) {
                error_log('[GHL_TOKEN_LOCK] wait_reload_ok registry_key=' . $registryKey);

                return $data;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $integration
     */
    private static function integrationHasFreshAccess(array $integration): bool
    {
        $token = $integration['access_token'] ?? null;
        if (!$token) {
            return false;
        }
        $expiresAt = $integration['expires_at'] ?? null;
        $expiresSeconds = $expiresAt instanceof \Google\Cloud\Core\Timestamp
            ? $expiresAt->get()->getTimestamp()
            : (int) $expiresAt;

        return $expiresSeconds > time() + 120;
    }

    private static function sanitizeDocId(string $id): string
    {
        $id = preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);

        return $id !== '' ? $id : 'unknown';
    }
}

final class GhlTokenProvider
{
    /**
     * True if ghl_tokens/{registryKey} exists (canonical path before legacy merge).
     */
    public static function canonicalDocumentExists($db, string $registryKey): bool
    {
        $snap = $db->collection('ghl_tokens')->document($registryKey)->snapshot();

        return $snap->exists();
    }

    /**
     * Load OAuth integration row for API calls. registryKey is the Firestore doc id used as primary key
     * (subaccount: location id; agency bulk endpoint: company id).
     *
     * @return array<string,mixed>|null
     */
    public static function loadIntegration($db, string $registryKey): ?array
    {
        $lookupSource = 'canonical';

        $canonicalSnap = $db->collection('ghl_tokens')->document($registryKey)->snapshot();
        $canonicalExisted = $canonicalSnap->exists();

        $data = null;
        if ($canonicalExisted) {
            $data = $canonicalSnap->data();
            $data['firestore_doc_id'] = $registryKey;
        } else {
            $query = $db->collection('ghl_tokens')
                ->where('location_id', '==', $registryKey)
                ->limit(1)
                ->documents();
            foreach ($query as $d) {
                if ($d->exists()) {
                    $data = $d->data();
                    $data['firestore_doc_id'] = $d->id();
                    $lookupSource = 'legacy_location_id_query';
                    error_log(sprintf(
                        '[GHL_TOKEN] legacy_fallback_lookup registry_key=%s legacy_doc_id=%s',
                        $registryKey,
                        $d->id()
                    ));
                    break;
                }
            }
        }

        if ($data && empty($data['companyId'])) {
            $resolvedCompanyId = self::resolveCompanyIdForLocation($db, $registryKey, $data);
            if ($resolvedCompanyId) {
                $data['companyId'] = $resolvedCompanyId;
                $legacyDocId = $data['firestore_doc_id'] ?? $registryKey;
                $db->collection('ghl_tokens')->document($legacyDocId)->set([
                    'companyId' => $resolvedCompanyId,
                ], ['merge' => true]);
            }
        }

        $legacyDocIdForBackfill = $data['firestore_doc_id'] ?? $registryKey;
        if ($data && $lookupSource === 'legacy_location_id_query' && $legacyDocIdForBackfill !== $registryKey) {
            $payload = $data;
            unset($payload['firestore_doc_id']);
            try {
                $db->collection('ghl_tokens')->document($registryKey)->set($payload, ['merge' => true]);
                error_log(sprintf(
                    '[GHL_TOKEN] legacy_fallback_backfill canonical_id=%s merged_from_legacy_doc=%s',
                    $registryKey,
                    $legacyDocIdForBackfill
                ));
            } catch (\Throwable $e) {
                error_log('[GHL_TOKEN] legacy_fallback_backfill_failed: ' . $e->getMessage());
            }
            $data['firestore_doc_id'] = $registryKey;
        }

        $locationHasToken = !empty($data['access_token']) || !empty($data['refresh_token']);

        if ($data && !$locationHasToken && !empty($data['companyId'])) {
            $companyId = $data['companyId'];
            $companyDoc = $db->collection('ghl_tokens')->document($companyId)->snapshot();
            if ($companyDoc->exists()) {
                $companyData = $companyDoc->data();
                $companyAccessToken = $companyData['access_token'] ?? null;

                if ($companyAccessToken) {
                    $ltCh = curl_init('https://services.leadconnectorhq.com/oauth/locationToken');
                    curl_setopt_array($ltCh, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query([
                            'companyId'  => $companyId,
                            'locationId' => $registryKey,
                        ]),
                        CURLOPT_HTTPHEADER => [
                            'Authorization: Bearer ' . $companyAccessToken,
                            'Content-Type: application/x-www-form-urlencoded',
                            'Accept: application/json',
                            'Version: 2021-07-28',
                        ],
                    ]);
                    $ltResp = curl_exec($ltCh);
                    $ltCode = curl_getinfo($ltCh, CURLINFO_HTTP_CODE);
                    curl_close($ltCh);

                    $ltData = json_decode($ltResp, true);
                    $ltOk = $ltCode >= 200 && $ltCode < 300;

                    if ($ltOk && !empty($ltData['access_token'])) {
                        $now = new \DateTimeImmutable();
                        $ltExpires = time() + (int) ($ltData['expires_in'] ?? 86400);
                        $locationPayload = [
                            'access_token'          => $ltData['access_token'],
                            'refresh_token'         => $companyData['refresh_token'] ?? null,
                            'expires_at'            => $ltExpires,
                            'client_id'             => $companyData['client_id'] ?? $companyData['appId'] ?? null,
                            'appId'                 => $companyData['appId'] ?? $companyData['client_id'] ?? null,
                            'appType'               => $companyData['appType'] ?? 'subaccount',
                            'userType'              => 'Location',
                            'location_id'           => $registryKey,
                            'companyId'             => $companyId,
                            'scope'                 => $companyData['scope'] ?? null,
                            'is_live'               => true,
                            'toggle_enabled'        => true,
                            'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
                            'provisioned_from_bulk' => true,
                        ];
                        $db->collection('ghl_tokens')->document($registryKey)->set($locationPayload, ['merge' => true]);
                        error_log('[GhlTokenProvider] Auto-provisioned location token for ' . $registryKey . ' via /oauth/locationToken.');

                        $locationPayload['firestore_doc_id'] = $registryKey;
                        $data = $locationPayload;
                    } else {
                        error_log('[GhlTokenProvider] locationToken exchange failed for ' . $registryKey . ' (HTTP ' . $ltCode . '). Using company token fallback.');
                        $companyData['firestore_doc_id'] = $companyId;
                        $companyData['location_id'] = $registryKey;
                        $data = $companyData;
                    }
                } else {
                    if (!empty($companyData['access_token']) || !empty($companyData['refresh_token'])) {
                        $companyData['firestore_doc_id'] = $companyId;
                        $companyData['location_id'] = $registryKey;
                        $data = $companyData;
                        error_log('[GhlTokenProvider] Using company token fallback for location ' . $registryKey . ' (location token missing).');
                    }
                }
            }
        }

        if ($data) {
            $data['_lookup_source'] = $lookupSource;
            $data['_canonical_preload_existed'] = $canonicalExisted;

            return $data;
        }

        return null;
    }

    /**
     * @param array<string,mixed>|null $data
     */
    public static function resolveCompanyIdForLocation($db, string $locationId, ?array $data = null): ?string
    {
        $candidates = [
            $data['companyId'] ?? null,
            $data['company_id'] ?? null,
        ];

        $agencySnap = $db->collection('agency_subaccounts')->document($locationId)->snapshot();
        if ($agencySnap->exists()) {
            $agencyData = $agencySnap->data();
            $candidates[] = $agencyData['agency_id'] ?? null;
            $candidates[] = $agencyData['companyId'] ?? null;
            $candidates[] = $agencyData['company_id'] ?? null;
        }

        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        if ($intSnap->exists()) {
            $intData = $intSnap->data();
            $candidates[] = $intData['companyId'] ?? null;
            $candidates[] = $intData['company_id'] ?? null;
            $candidates[] = $intData['agency_id'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $companyId = trim((string) $candidate);
            if ($companyId !== '' && $companyId !== $locationId) {
                return $companyId;
            }
        }

        return null;
    }

    public static function classifyOAuthRefreshFailure(int $httpCode, ?array $parsedBody): string
    {
        $err = strtolower((string) ($parsedBody['error'] ?? ''));
        $desc = strtolower((string) ($parsedBody['error_description'] ?? ''));

        if ($err === 'invalid_grant' || str_contains($desc, 'invalid_grant')) {
            return GhlOAuthRefreshException::REASON_INVALID_GRANT;
        }
        if ($err === 'invalid_client') {
            return GhlOAuthRefreshException::REASON_INVALID_GRANT;
        }
        if ($httpCode >= 500 || $httpCode === 429 || $httpCode === 0) {
            return GhlOAuthRefreshException::REASON_TRANSIENT;
        }
        if ($err === 'temporarily_unavailable' || str_contains($desc, 'temporarily')) {
            return GhlOAuthRefreshException::REASON_TRANSIENT;
        }

        return GhlOAuthRefreshException::REASON_OTHER;
    }
}
