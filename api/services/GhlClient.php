<?php

require_once __DIR__ . '/GhlTokenProvider.php';

/**
 * GhlClient — Reusable GHL API client.
 *
 * Extracted from the procedural code in ghl_contacts.php.
 * Handles Firestore integration lookup, proactive token refresh,
 * automatic 401 retry, and generic HTTP requests against the GHL API v2.
 */
class GhlClient
{
    private $db;

    /** GHL Location ID passed to API URLs and locationToken exchanges. */
    private string $locationId;

    /** Firestore `ghl_tokens/{id}` primary key used to load OAuth state (JWT `ghl_token_ref` overrides). */
    private string $tokenRegistryId;

    private array $integration;
    private string $apiUrl = 'https://services.leadconnectorhq.com';

    /** Canonical ghl_tokens/{tokenRegistryId} existed before legacy merge (internal telemetry). */
    private bool $canonicalDocExistedAtLoad = false;

    /**
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId         GHL location id for `/contacts/` etc.
     * @param string|null $tokenRegistryId  Optional Firestore OAuth doc id (defaults to $locationId)
     * @throws \RuntimeException if integration not found in Firestore
     */
    public function __construct($db, string $locationId, ?string $tokenRegistryId = null)
    {
        $this->db = $db;
        $this->locationId = $locationId;
        $this->tokenRegistryId = $tokenRegistryId ?? $locationId;

        $integration = $this->loadIntegration($this->tokenRegistryId);
        if (!$integration) {
            throw new \RuntimeException(
                'GHL integration not found for token_registry_key: ' . $this->tokenRegistryId
                . ' (api_location=' . $this->locationId . ')'
            );
        }

        $this->integration = $integration;
    }

    // ── Public API ──────────────────────────────────────────────────────

    /**
     * Generic HTTP request against the GHL API.
     *
     * Handles proactive token refresh (within 5 min of expiry) and
     * automatic retry on 401 after refreshing the token.
     *
     * @param string      $method      HTTP method (GET, POST, PUT, DELETE)
     * @param string      $path        API path (e.g. '/contacts/?locationId=xxx')
     * @param string|null $body        JSON-encoded request body (for POST/PUT)
     * @param string      $apiVersion  GHL API version header (default: 2021-07-28)
     * @return array{status: int, body: string}
     */
    public function request(string $method, string $path, ?string $body = null, string $apiVersion = '2021-07-28'): array
    {
        // Proactive refresh if token expires within 5 minutes
        $this->proactiveRefresh();

        $url = $this->apiUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->integration['access_token'],
            'Content-Type: application/json',
            'Version: ' . $apiVersion,
        ];

        $attempt = 1;
        while ($attempt <= 2) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT        => 12,
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $body;
            } elseif ($method === 'PUT') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_POSTFIELDS] = $body;
            } elseif ($method === 'DELETE') {
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                if ($body !== null) {
                    $options[CURLOPT_POSTFIELDS] = $body;
                }
            }

            curl_setopt_array($ch, $options);
            $responseBody = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($responseBody === false || $status === 0) {
                error_log(
                    '[GhlClient] request transport failure '
                    . $method
                    . ' '
                    . $path
                    . ' curl_errno='
                    . $curlErrno
                    . ' curl_error='
                    . $curlError
                );

                return [
                    'status' => 503,
                    'body' => json_encode([
                        'error' => 'GHL API temporarily unavailable',
                        'requires_reconnect' => false,
                    ]),
                ];
            }

            // Retry once on 401 after refreshing the token
            if ($status === 401 && $attempt === 1) {
                try {
                    $this->refreshToken();
                    // Update the Authorization header for retry
                    $headers[0] = 'Authorization: Bearer ' . $this->integration['access_token'];
                    $attempt++;
                    continue;
                } catch (GhlOAuthRefreshException $e) {
                    error_log('GhlClient refresh after 401 (classified): ' . $e->getReasonCode() . ' ' . $e->getMessage());
                    if ($e->shouldPromptReconnect()) {
                        $body = [
                            'error' => 'Token refresh failed',
                            'requires_reconnect' => true,
                        ];
                        if (self::shouldIncludeDebug()) {
                            $body['debug'] = $this->buildSafeDebugPayload($e);
                        }

                        return [
                            'status' => 401,
                            'body'   => json_encode($body),
                        ];
                    }

                    $body = [
                        'error' => 'GHL token temporarily unavailable',
                        'requires_reconnect' => false,
                    ];
                    if (self::shouldIncludeDebug()) {
                        $body['debug'] = $this->buildSafeDebugPayload($e);
                    }

                    return [
                        'status' => 503,
                        'body'   => json_encode($body),
                    ];
                } catch (\Exception $e) {
                    error_log('GhlClient refresh after 401: ' . $e->getMessage());

                    $body = [
                        'error' => 'GHL token temporarily unavailable',
                        'requires_reconnect' => false,
                    ];
                    if (self::shouldIncludeDebug()) {
                        $body['debug'] = $this->buildSafeDebugPayload(null);
                    }

                    return [
                        'status' => 503,
                        'body'   => json_encode($body),
                    ];
                }
            }

            return ['status' => $status, 'body' => $responseBody];
        }

        // Should never reach here, but just in case
        return ['status' => 500, 'body' => json_encode(['error' => 'Unexpected error in request loop'])];
    }

    /**
     * Get the current access token (useful for external callers that need it).
     */
    public function getAccessToken(): ?string
    {
        return $this->integration['access_token'] ?? null;
    }

    /**
     * Get the loaded integration data.
     */
    public function getIntegration(): array
    {
        return $this->integration;
    }

    /**
     * Get the location ID this client was initialized with.
     */
    public function getLocationId(): string
    {
        return $this->locationId;
    }

    // ── Private helpers ─────────────────────────────────────────────────

    private static function shouldIncludeDebug(): bool
    {
        $flag = getenv('DEBUG_GHL_TOKEN');
        if ($flag === false) {
            return false;
        }

        $flag = strtolower(trim((string) $flag));

        return $flag === '1' || $flag === 'true' || $flag === 'yes' || $flag === 'on';
    }

    /** @return array<string,mixed> */
    private function buildSafeDebugPayload(?GhlOAuthRefreshException $e = null): array
    {
        $out = [
            'api_location_id' => $this->locationId,
            'token_registry_id' => $this->tokenRegistryId,
            'cloud_run_service' => getenv('K_SERVICE') ?: null,
            'cloud_run_revision' => getenv('K_REVISION') ?: null,
            'canonical_doc_existed_at_load' => $this->canonicalDocExistedAtLoad,
            'integration_doc_id' => $this->integration['firestore_doc_id'] ?? null,
            'integration_user_type' => $this->integration['userType'] ?? null,
            'integration_app_type' => $this->integration['appType'] ?? null,
            'integration_company_id' => $this->integration['companyId'] ?? null,
            'has_refresh_token' => !empty($this->integration['refresh_token']),
            'has_access_token' => !empty($this->integration['access_token']),
        ];

        $expiresAt = $this->integration['expires_at'] ?? null;
        $expiresSeconds = $expiresAt instanceof \Google\Cloud\Core\Timestamp
            ? $expiresAt->get()->getTimestamp()
            : (int) $expiresAt;
        if ($expiresSeconds > 0) {
            $out['expires_at_unix'] = $expiresSeconds;
            $out['expires_in_sec'] = $expiresSeconds - time();
        }

        if ($e) {
            $out['refresh_reason'] = $e->getReasonCode();
            $out['would_prompt_reconnect'] = $e->shouldPromptReconnect();
            $ctx = $e->getContext();
            if (!empty($ctx)) {
                // Context must remain secret-safe (no access_token / refresh_token / client_secret).
                $out['refresh_context'] = $ctx;
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $data */
    private static function redactGhlSecretsInArray(array $data): array
    {
        $secretKeys = ['access_token', 'refresh_token', 'id_token', 'client_secret'];
        $out = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $secretKeys, true)) {
                $out[$k] = ($v !== null && $v !== '') ? '[REDACTED]' : $v;
            } elseif (is_array($v)) {
                $out[$k] = self::redactGhlSecretsInArray($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }

    private static function redactGhlResponseForLog(string $raw): string
    {
        $trim = trim($raw);
        if ($trim === '') {
            return '';
        }
        $decoded = json_decode($trim, true);
        if (is_array($decoded)) {
            return json_encode(self::redactGhlSecretsInArray($decoded));
        }
        $redacted = preg_replace(
            '/eyJ[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}\.[a-zA-Z0-9_-]{10,}/',
            '[REDACTED_JWT]',
            $trim
        );

        return $redacted !== null ? $redacted : $trim;
    }

    /**
     * Load GHL integration from Firestore via {@see GhlTokenProvider} (canonical path + legacy fallback).
     *
     * @return array|null  Integration data or null if not found
     */
    private function loadIntegration(string $registryKey): ?array
    {
        $data = GhlTokenProvider::loadIntegration($this->db, $registryKey);
        if ($data) {
            $this->canonicalDocExistedAtLoad = !empty($data['_canonical_preload_existed']);
            unset($data['_lookup_source'], $data['_canonical_preload_existed']);

            return $data;
        }

        return null;
    }

    /**
     * Clear the local cache for this location (useful after explicit refresh).
     */
    public function clearCache(): void
    {
        require_once __DIR__ . '/Cache.php';
        $cache = new Cache('tokens');
        $cache->delete('token_' . $this->tokenRegistryId);
    }

    /**
     * Proactively refresh token if it expires within 5 minutes.
     */
    private function proactiveRefresh(): void
    {
        if (!isset($this->integration['expires_at'])) {
            return;
        }

        $expiresAt = $this->integration['expires_at'];
        $now = time();

        // Handle Google Cloud Timestamp objects
        $expiresSeconds = $expiresAt instanceof \Google\Cloud\Core\Timestamp
            ? $expiresAt->get()->getTimestamp()
            : (int) $expiresAt;

        if ($expiresSeconds - $now < 300) {
            try {
                $this->refreshToken();
            } catch (\Throwable $e) {
                // Log but continue — old token may still work (clock skew)
                error_log('GhlClient proactive refresh failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Refresh GHL OAuth token and update Firestore.
     *
     * @throws GhlOAuthRefreshException
     */
    private function refreshToken(): void
    {
        $canonicalExists = GhlTokenProvider::canonicalDocumentExists($this->db, $this->tokenRegistryId)
            || $this->canonicalDocExistedAtLoad;

        $storedClientId = $this->integration['client_id'] ?? $this->integration['appId'] ?? null;
        $refreshToken = $this->integration['refresh_token'] ?? null;
        $docId = $this->integration['firestore_doc_id'] ?? $this->tokenRegistryId;
        $companyId = $this->integration['companyId'] ?? null;
        if (!$companyId) {
            $companyId = GhlTokenProvider::resolveCompanyIdForLocation($this->db, $this->locationId, $this->integration);
            if ($companyId) {
                $this->integration['companyId'] = $companyId;
            }
        }

        $subaccountClientId = getenv('GHL_CLIENT_ID') ?: '';
        $subaccountSecret = getenv('GHL_CLIENT_SECRET') ?: '';
        $agencyClientId = getenv('GHL_AGENCY_CLIENT_ID') ?: '';
        $agencySecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: '';

        $companyData = null;
        $companyAccessToken = null;
        $companyExpiresAt = 0;
        $companyRefresh = null;

        if ($companyId) {
            $companyDoc = $this->db->collection('ghl_tokens')->document($companyId)->snapshot();
            if ($companyDoc->exists()) {
                $companyData = $companyDoc->data();
                $companyAccessToken = $companyData['access_token'] ?? null;
                $companyRefresh = $companyData['refresh_token'] ?? null;
                $companyExpiresRaw = $companyData['expires_at'] ?? 0;
                $companyExpiresAt = $companyExpiresRaw instanceof \Google\Cloud\Core\Timestamp
                    ? $companyExpiresRaw->get()->getTimestamp()
                    : (int) $companyExpiresRaw;
            }
        }

        $isLocationDoc = ($this->integration['userType'] ?? null) === 'Location'
            && $companyId
            && $companyId !== $this->locationId;
        // IMPORTANT: Do NOT infer "company-backed" purely from refresh_token equality.
        // Some installs copy the same refresh_token into both company+location docs; treating
        // that as bulk/company-backed incorrectly forces a locationToken exchange, which can 400.
        $isCompanyBackedLocation = $isLocationDoc && (
            !empty($this->integration['provisioned_from_bulk'])
            || !empty($this->integration['copied_from'])
            || (($this->integration['appType'] ?? null) === 'agency')
        );
        $isBulkProvisioned = !empty($this->integration['provisioned_from_bulk']) || $isCompanyBackedLocation;

        // If this is a Location token doc and it already has its own refresh_token,
        // always attempt a Location refresh first. Some docs keep provisioned_from_bulk=true
        // even after a location-scoped token is established; forcing the company->locationToken
        // exchange in that state can 400 and causes 503 loops.
        $forceLocationRefresh = (($this->integration['userType'] ?? null) === 'Location')
            && !empty($this->integration['refresh_token']);
        if ($forceLocationRefresh) {
            $isBulkProvisioned = false;
        }

        // Recovery path: some legacy / partially-provisioned location docs can have an access_token
        // but no refresh_token, while the linked company doc still has a valid refresh_token.
        // In that case, refresh as Company and exchange to a Location token.
        if ((!$refreshToken || $refreshToken === '') && $companyId && $companyId !== $this->tokenRegistryId && $companyRefresh) {
            $refreshToken = $companyRefresh;
            $isBulkProvisioned = true;
            error_log('[GHL_TOKEN] refresh_token_missing_using_company_refresh registry_key=' . $this->tokenRegistryId . ' companyId=' . $companyId);
        } elseif ($isBulkProvisioned && !$forceLocationRefresh && $companyRefresh) {
            $refreshToken = $companyRefresh;
        }

        $hadRefresh = $refreshToken !== null && $refreshToken !== '';

        if (!$hadRefresh) {
            throw new GhlOAuthRefreshException(
                'GHL Refresh Error: Missing refresh_token (in Firestore)',
                GhlOAuthRefreshException::REASON_MISSING_REFRESH,
                $canonicalExists,
                false,
                false,
                false,
                [
                    'hint' => 'missing_refresh_token',
                ]
            );
        }

        $lockKey = ($isBulkProvisioned && $companyId) ? 'company_' . $companyId : 'token_' . $docId;

        $lockAcquired = GhlTokenRefreshLock::tryAcquire($this->db, $lockKey);
        if (!$lockAcquired) {
            error_log('[GHL_TOKEN_LOCK] blocked registry_key=' . $this->tokenRegistryId . ' api_location=' . $this->locationId . ' lock=' . $lockKey);
            $reloaded = GhlTokenRefreshLock::waitAndReloadIntegration($this->db, $this->tokenRegistryId);
            if ($reloaded) {
                unset($reloaded['_lookup_source'], $reloaded['_canonical_preload_existed']);
                $this->integration = $reloaded;
                $this->clearCache();

                return;
            }
            throw new GhlOAuthRefreshException(
                'Peer OAuth refresh did not complete in time',
                GhlOAuthRefreshException::REASON_LOCK_ACTIVE,
                $canonicalExists,
                $hadRefresh,
                false,
                true,
                [
                    'lock_key' => $lockKey,
                ]
            );
        }

        try {
            $this->runOAuthRefreshBody(
                $canonicalExists,
                $hadRefresh,
                $storedClientId,
                $refreshToken,
                $docId,
                $companyId,
                $companyData,
                $companyAccessToken,
                $companyExpiresAt,
                $companyRefresh,
                $isBulkProvisioned,
                $subaccountClientId,
                $subaccountSecret,
                $agencyClientId,
                $agencySecret
            );
        } finally {
            GhlTokenRefreshLock::release($this->db, $lockKey);
        }
    }

    /**
     * @param array<string,mixed>|null $companyData
     */
    private function runOAuthRefreshBody(
        bool $canonicalExists,
        bool $hadRefresh,
        ?string $storedClientId,
        string $refreshToken,
        string $docId,
        ?string $companyId,
        ?array $companyData,
        ?string $companyAccessToken,
        int $companyExpiresAt,
        ?string $companyRefresh,
        bool $isBulkProvisioned,
        string $subaccountClientId,
        string $subaccountSecret,
        string $agencyClientId,
        string $agencySecret
    ): void {
        $peek = GhlTokenProvider::loadIntegration($this->db, $this->tokenRegistryId);
        if ($peek) {
            $expiresRaw = $peek['expires_at'] ?? 0;
            $expiresSec = $expiresRaw instanceof \Google\Cloud\Core\Timestamp
                ? $expiresRaw->get()->getTimestamp()
                : (int) $expiresRaw;
            if (!empty($peek['access_token']) && $expiresSec > time() + 120) {
                unset($peek['_lookup_source'], $peek['_canonical_preload_existed']);
                $this->integration = $peek;
                $this->clearCache();

                return;
            }
        }

        $companyTokenSkippedRefresh = false;
        $data = null;
        $clientId = $storedClientId ?: $subaccountClientId;

        if ($isBulkProvisioned && $companyAccessToken && time() < ($companyExpiresAt - 300)) {
            $companyTokenSkippedRefresh = true;
            $data = [
                'access_token'  => $companyAccessToken,
                'refresh_token' => $companyRefresh,
                'expires_in'    => $companyExpiresAt - time(),
            ];
            $clientId = $companyData['client_id'] ?? $companyData['appId'] ?? $clientId;
        }

        $attemptedCredentialRefresh = false;

        if (!$companyTokenSkippedRefresh) {
            $attemptedCredentialRefresh = true;
            $preferredUserType = $isBulkProvisioned ? 'Company' : ($this->integration['userType'] ?? 'Location');
            $credentialCandidates = [];
            $addCandidate = static function (array &$items, string $id, string $secret, string $userType, string $label): void {
                if ($id === '' || $secret === '') {
                    return;
                }
                $key = $id . ':' . $userType;
                if (isset($items[$key])) {
                    return;
                }
                $items[$key] = [
                    'client_id' => $id,
                    'client_secret' => $secret,
                    'user_type' => $userType,
                    'label' => $label,
                ];
            };

            if ($storedClientId === $agencyClientId) {
                $addCandidate($credentialCandidates, $agencyClientId, $agencySecret, $preferredUserType, 'stored-agency');
            } else {
                $addCandidate($credentialCandidates, $subaccountClientId, $subaccountSecret, $preferredUserType, 'stored-subaccount');
            }

            if ($isBulkProvisioned || $companyId) {
                $addCandidate($credentialCandidates, $subaccountClientId, $subaccountSecret, 'Company', 'subaccount-company');
                $addCandidate($credentialCandidates, $agencyClientId, $agencySecret, 'Company', 'agency-company');
            }

            if (!$isBulkProvisioned) {
                $addCandidate($credentialCandidates, $subaccountClientId, $subaccountSecret, 'Location', 'subaccount-location');
            }

            $lastHint = 'invalid response';
            $lastHttpCode = 0;
            $lastParsed = null;
            $lastLabel = null;
            $lastUserType = null;
            $successUserType = null;
            foreach ($credentialCandidates as $candidate) {
                $ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => http_build_query([
                        'client_id'     => $candidate['client_id'],
                        'client_secret' => $candidate['client_secret'],
                        'grant_type'    => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'user_type'     => $candidate['user_type'],
                    ]),
                    CURLOPT_HTTPHEADER     => [
                        'Accept: application/json',
                        'Content-Type: application/x-www-form-urlencoded',
                        'Version: 2021-07-28',
                    ],
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT        => 12,
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $parsed = json_decode((string) $response, true);
                $lastHttpCode = $httpCode;
                $lastParsed = is_array($parsed) ? $parsed : null;
                $lastLabel = (string) ($candidate['label'] ?? '');
                $lastUserType = (string) ($candidate['user_type'] ?? '');

                if ($httpCode === 200 && is_array($parsed) && !empty($parsed['access_token'])) {
                    $data = $parsed;
                    $clientId = $candidate['client_id'];
                    error_log('[GhlClient] oauth/token refresh succeeded via ' . $candidate['label']);
                    $successUserType = (string) ($candidate['user_type'] ?? '');
                    break;
                }

                $lastHint = is_array($parsed)
                    ? ($parsed['error_description'] ?? $parsed['message'] ?? $parsed['error'] ?? 'invalid response')
                    : 'invalid response';
                error_log(
                    '[GhlClient] oauth/token refresh candidate failed via '
                    . $candidate['label']
                    . ' HTTP '
                    . $httpCode
                    . ': '
                    . self::redactGhlResponseForLog((string) $response)
                );
            }

            if (!is_array($data) || empty($data['access_token'])) {
                $reason = GhlTokenProvider::classifyOAuthRefreshFailure($lastHttpCode, $lastParsed);
                $ctx = [
                    'oauth_http_code' => $lastHttpCode,
                    'oauth_candidate' => $lastLabel,
                    'oauth_user_type' => $lastUserType,
                    'oauth_error' => is_array($lastParsed) ? ($lastParsed['error'] ?? null) : null,
                    'oauth_error_description' => is_array($lastParsed) ? ($lastParsed['error_description'] ?? null) : null,
                ];
                throw new GhlOAuthRefreshException(
                    'GHL token refresh failed: ' . $lastHint,
                    $reason,
                    $canonicalExists,
                    $hadRefresh,
                    $attemptedCredentialRefresh,
                    false,
                    $ctx
                );
            }

            // If refresh succeeded with a Company token but our API calls require a Location token,
            // exchange it now to avoid "authClass type is not allowed to access this scope" 401s.
            $desired = (string) ($this->integration['userType'] ?? 'Location');
            if ($successUserType === 'Company' && $desired === 'Location' && $companyId) {
                $ltResult = GhlTokenProvider::exchangeLocationToken(
                    (string) ($data['access_token'] ?? ''),
                    (string) $companyId,
                    $this->locationId
                );
                $ltData = $ltResult['data'];
                $ltCode = $ltResult['code'];
                $ltOk = !empty($ltResult['ok']);

                if (!$ltOk || empty($ltData['access_token'])) {
                    error_log(
                        '[GhlClient] locationToken after company-refresh failed HTTP '
                        . $ltCode . ': '
                        . json_encode($ltResult['failures'] ?? [])
                    );
                    $ltReason = ($ltCode >= 500 || $ltCode === 0 || $ltCode === 429)
                        ? GhlOAuthRefreshException::REASON_TRANSIENT
                        : GhlOAuthRefreshException::REASON_OTHER;
                    throw new GhlOAuthRefreshException(
                        "GHL locationToken exchange failed after company refresh (HTTP {$ltCode})",
                        $ltReason,
                        $canonicalExists,
                        $hadRefresh,
                        true,
                        false,
                        [
                            'location_token_http_code' => $ltCode,
                        ]
                    );
                }

                // Replace access token payload with a location-scoped token; keep refresh_token.
                $data['access_token'] = $ltData['access_token'];
                $data['expires_in'] = $ltData['expires_in'] ?? 86400;
            }
        }

        $now = new \DateTimeImmutable();

        if ($isBulkProvisioned && $companyId) {
            $companyExpires = (int) ($data['expires_in'] ?? 0);
            $companyExpiresAtUnix = time() + $companyExpires;

            $this->db->collection('ghl_tokens')->document($companyId)->set([
                'access_token'  => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_at'    => $companyExpiresAtUnix,
                'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
                'raw_refresh'   => $data,
            ], ['merge' => true]);

            $ltResult = GhlTokenProvider::exchangeLocationToken(
                (string) $data['access_token'],
                (string) $companyId,
                $this->locationId
            );
            $ltData = $ltResult['data'];
            $ltCode = $ltResult['code'];
            $ltOk = !empty($ltResult['ok']);

            if (!$ltOk || empty($ltData['access_token'])) {
                error_log(
                    '[GhlClient] locationToken after refresh failed HTTP '
                    . $ltCode . ': '
                    . json_encode($ltResult['failures'] ?? [])
                );
                $ltReason = ($ltCode >= 500 || $ltCode === 0)
                    ? GhlOAuthRefreshException::REASON_TRANSIENT
                    : GhlOAuthRefreshException::REASON_OTHER;
                throw new GhlOAuthRefreshException(
                    "GHL locationToken exchange failed after refresh (HTTP {$ltCode})",
                    $ltReason,
                    $canonicalExists,
                    $hadRefresh,
                    true,
                    false,
                    [
                        'location_token_http_code' => $ltCode,
                    ]
                );
            }

            $data['access_token'] = $ltData['access_token'];
            $data['expires_in'] = $ltData['expires_in'] ?? 86400;
        }

        $expires = (int) ($data['expires_in'] ?? 0);
        $expiresAtUnix = time() + $expires;

        $updateData = [
            'access_token'  => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? ($this->integration['refresh_token'] ?? null),
            'expires_at'    => $expiresAtUnix,
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            'raw_refresh'   => $data,
            'client_id'     => $clientId,
            'appId'         => $clientId,
        ];

        if ($isBulkProvisioned && $companyId) {
            $updateData['provisioned_from_bulk'] = true;
            $updateData['companyId'] = $companyId;
        }

        $this->db->collection('ghl_tokens')->document($docId)->set($updateData, ['merge' => true]);

        $this->integration['access_token'] = $data['access_token'] ?? null;
        $this->integration['refresh_token'] = $updateData['refresh_token'];
        $this->integration['expires_at'] = $expiresAtUnix;

        $this->clearCache();
    }
}
