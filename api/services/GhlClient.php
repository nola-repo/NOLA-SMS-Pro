<?php

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
    private string $locationId;
    private array $integration;
    private string $apiUrl = 'https://services.leadconnectorhq.com';

    /**
     * @param \Google\Cloud\Firestore\FirestoreClient $db
     * @param string $locationId  GHL Location ID (required — no single-tenant fallback)
     * @throws \RuntimeException if integration not found in Firestore
     */
    public function __construct($db, string $locationId)
    {
        $this->db = $db;
        $this->locationId = $locationId;

        $integration = $this->loadIntegration($locationId);
        if (!$integration) {
            throw new \RuntimeException("GHL integration not found for location: {$locationId}");
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
            ];

            if ($method === 'POST') {
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = $body;
            } elseif ($method === 'PUT') {
                $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
                $options[CURLOPT_POSTFIELDS] = $body;
            } elseif ($method === 'DELETE') {
                $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
            }

            curl_setopt_array($ch, $options);
            $responseBody = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Retry once on 401 after refreshing the token
            if ($status === 401 && $attempt === 1) {
                try {
                    $this->refreshToken();
                    // Update the Authorization header for retry
                    $headers[0] = 'Authorization: Bearer ' . $this->integration['access_token'];
                    $attempt++;
                    continue;
                } catch (\Exception $e) {
                    // Never forward exception text to the client — it may echo upstream OAuth bodies.
                    error_log('GhlClient refresh after 401: ' . $e->getMessage());
                    return [
                        'status' => 401,
                        'body'   => json_encode([
                            'error' => 'Token refresh failed',
                            'requires_reconnect' => true,
                        ]),
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

    private function resolveCompanyIdForLocation(string $locationId, ?array $data = null): ?string
    {
        $candidates = [
            $data['companyId'] ?? null,
            $data['company_id'] ?? null,
        ];

        $agencySnap = $this->db->collection('agency_subaccounts')->document($locationId)->snapshot();
        if ($agencySnap->exists()) {
            $agencyData = $agencySnap->data();
            $candidates[] = $agencyData['agency_id'] ?? null;
            $candidates[] = $agencyData['companyId'] ?? null;
            $candidates[] = $agencyData['company_id'] ?? null;
        }

        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $intSnap = $this->db->collection('integrations')->document($intDocId)->snapshot();
        if ($intSnap->exists()) {
            $intData = $intSnap->data();
            $candidates[] = $intData['companyId'] ?? null;
            $candidates[] = $intData['company_id'] ?? null;
            $candidates[] = $intData['agency_id'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $companyId = trim((string)$candidate);
            if ($companyId !== '' && $companyId !== $locationId) {
                return $companyId;
            }
        }

        return null;
    }

    /**
     * Load GHL integration from Firestore (same logic as getGHLIntegration).
     *
     * @return array|null  Integration data or null if not found
     */
    private function loadIntegration(string $locationId): ?array
    {
        require_once __DIR__ . '/Cache.php';
        $cache = new Cache('tokens');
        $cacheKey = 'token_' . $locationId;
        $cacheTTL = 300; // 5 minutes buffer — matches proactive refresh window

        // 1. File cache disabled — unsafe across Cloud Run multi-instance deployments.
        // Each container has an independent filesystem; a token refreshed by Instance A
        // will not be visible to Instance B's cache, leading to stale refresh_token 400s.
        // $cachedData = $cache->get($cacheKey, $cacheTTL); // DISABLED
        // if ($cachedData) { return $cachedData; }         // DISABLED

        // 2. Not in cache? Hit Firestore (Primary: doc ID = raw locationId)
        $data = null;
        $doc = $this->db->collection('ghl_tokens')->document($locationId)->snapshot();
        if ($doc->exists()) {
            $data = $doc->data();
            $data['firestore_doc_id'] = $locationId;
        } else {
            // Fallback: search by location_id field (handles legacy docs)
            $query = $this->db->collection('ghl_tokens')
                ->where('location_id', '==', $locationId)
                ->limit(1)
                ->documents();
            foreach ($query as $d) {
                if ($d->exists()) {
                    $data = $d->data();
                    $data['firestore_doc_id'] = $d->id();
                    break;
                }
            }
        }

        // 2.5. Agency-Level Fallback (SCOPE-SAFE):
        // Only use the company token when the location document has NO usable token.
        // When found, automatically exchange for a location-scoped token via
        // GHL's /oauth/locationToken endpoint — which returns authClass:Location
        // tokens valid for /contacts/ and /conversations/.
        if ($data && empty($data['companyId'])) {
            $resolvedCompanyId = $this->resolveCompanyIdForLocation($locationId, $data);
            if ($resolvedCompanyId) {
                $data['companyId'] = $resolvedCompanyId;
                $docId = $data['firestore_doc_id'] ?? $locationId;
                $this->db->collection('ghl_tokens')->document($docId)->set([
                    'companyId' => $resolvedCompanyId,
                ], ['merge' => true]);
            }
        }

        $locationHasToken = !empty($data['access_token']) || !empty($data['refresh_token']);

        if ($data && !$locationHasToken && !empty($data['companyId'])) {
            $companyId  = $data['companyId'];
            $companyDoc = $this->db->collection('ghl_tokens')->document($companyId)->snapshot();
            if ($companyDoc->exists()) {
                $companyData = $companyDoc->data();
                $companyAccessToken = $companyData['access_token'] ?? null;

                if ($companyAccessToken) {
                    // Try to exchange for a location-scoped token automatically
                    $ltCh = curl_init('https://services.leadconnectorhq.com/oauth/locationToken');
                    curl_setopt_array($ltCh, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query([
                            'companyId'  => $companyId,
                            'locationId' => $locationId,
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
                    // GHL may return 201 Created for successful token issuance; treat any 2xx as success.
                    $ltOk = $ltCode >= 200 && $ltCode < 300;

                    if ($ltOk && !empty($ltData['access_token'])) {
                        // Save location-scoped token to Firestore
                        $now          = new \DateTimeImmutable();
                        $ltExpires    = time() + (int)($ltData['expires_in'] ?? 86400);
                        $locationPayload = [
                            'access_token'          => $ltData['access_token'],
                            'refresh_token'         => $companyData['refresh_token'] ?? null,
                            'expires_at'            => $ltExpires,
                            'client_id'             => $companyData['client_id'] ?? $companyData['appId'] ?? null,
                            'appId'                 => $companyData['appId']      ?? $companyData['client_id'] ?? null,
                            'appType'               => $companyData['appType']    ?? 'subaccount',
                            'userType'              => 'Location',
                            'location_id'           => $locationId,
                            'companyId'             => $companyId,
                            'scope'                 => $companyData['scope']      ?? null,
                            'is_live'               => true,
                            'toggle_enabled'        => true,
                            'updated_at'            => new \Google\Cloud\Core\Timestamp($now),
                            'provisioned_from_bulk' => true,
                        ];
                        $this->db->collection('ghl_tokens')->document($locationId)->set($locationPayload, ['merge' => true]);
                        error_log("[GhlClient] Auto-provisioned location token for {$locationId} via /oauth/locationToken.");

                        $locationPayload['firestore_doc_id'] = $locationId;
                        $data = $locationPayload;
                    } else {
                        // locationToken exchange failed — fall back to company token as read-only context
                        error_log("[GhlClient] locationToken exchange failed for {$locationId} (HTTP {$ltCode}). Using company token fallback.");
                        $companyData['firestore_doc_id'] = $companyId;
                        $companyData['location_id']      = $locationId;
                        $data = $companyData;
                    }
                } else {
                    // Company doc has no access_token — still use it if it has a refresh token
                    if (!empty($companyData['access_token']) || !empty($companyData['refresh_token'])) {
                        $companyData['firestore_doc_id'] = $companyId;
                        $companyData['location_id']      = $locationId;
                        $data = $companyData;
                        error_log("[GhlClient] Using company token fallback for location {$locationId} (location token missing).");
                    }
                }
            }
        }


        // 3. Cache write disabled — see note above re: Cloud Run multi-instance.
        // $cache->set($cacheKey, $data); // DISABLED
        if ($data) {
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
        $cache->delete('token_' . $this->locationId);
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
            } catch (\Exception $e) {
                // Log but continue — old token may still work (clock skew)
                error_log('GhlClient proactive refresh failed: ' . $e->getMessage());
            }
        }
    }

    /**
     * Refresh GHL OAuth token and update Firestore.
     * Same logic as refreshGHLToken() in ghl_contacts.php.
     *
     * @throws \Exception on refresh failure
     */
    private function refreshToken(): void
    {
        $storedClientId = $this->integration['client_id'] ?? $this->integration['appId'] ?? null;
        $refreshToken   = $this->integration['refresh_token'] ?? null;
        $docId          = $this->integration['firestore_doc_id'] ?? $this->locationId;
        $companyId      = $this->integration['companyId'] ?? null;
        if (!$companyId) {
            $companyId = $this->resolveCompanyIdForLocation($this->locationId, $this->integration);
            if ($companyId) {
                $this->integration['companyId'] = $companyId;
            }
        }

        $subaccountClientId = getenv('GHL_CLIENT_ID') ?: '6999da2b8f278296d95f7274-mmn30t4f';
        $subaccountSecret   = getenv('GHL_CLIENT_SECRET') ?: 'd91017ad-f4eb-461f-8967-b1d51cd1c1eb';
        $agencyClientId     = getenv('GHL_AGENCY_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
        $agencySecret       = getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';

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
                    : (int)$companyExpiresRaw;
            }
        }

        $isLocationDoc = ($this->integration['userType'] ?? null) === 'Location'
            && $companyId
            && $companyId !== $this->locationId;
        $usesCompanyRefresh = $companyRefresh && $refreshToken && hash_equals((string)$companyRefresh, (string)$refreshToken);
        $isCompanyBackedLocation = $isLocationDoc && (
            !empty($this->integration['provisioned_from_bulk'])
            || !empty($this->integration['copied_from'])
            || (($this->integration['appType'] ?? null) === 'agency')
            || $usesCompanyRefresh
        );
        $isBulkProvisioned = !empty($this->integration['provisioned_from_bulk']) || $isCompanyBackedLocation;

        if ($isBulkProvisioned && $companyRefresh) {
            $refreshToken = $companyRefresh;
        }

        if (!$refreshToken) {
            throw new \Exception('GHL Refresh Error: Missing refresh_token (in Firestore)');
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

        if (!$companyTokenSkippedRefresh) {
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
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                $parsed = json_decode((string)$response, true);
                if ($httpCode === 200 && is_array($parsed) && !empty($parsed['access_token'])) {
                    $data = $parsed;
                    $clientId = $candidate['client_id'];
                    error_log('[GhlClient] oauth/token refresh succeeded via ' . $candidate['label']);
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
                    . self::redactGhlResponseForLog((string)$response)
                );
            }

            if (!is_array($data) || empty($data['access_token'])) {
                throw new \Exception("GHL token refresh failed: {$lastHint}");
            }
        }

        $now = new \DateTimeImmutable();

        // If this was a bulk-provisioned token, $data now contains a COMPANY token.
        // We must save it to the Company doc, and then exchange it for a LOCATION token.
        if ($isBulkProvisioned && $companyId) {
            $companyExpires = (int)($data['expires_in'] ?? 0);
            $companyExpiresAtUnix = time() + $companyExpires;

            $this->db->collection('ghl_tokens')->document($companyId)->set([
                'access_token'  => $data['access_token'] ?? null,
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'expires_at'    => $companyExpiresAtUnix,
                'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
                'raw_refresh'   => $data,
            ], ['merge' => true]);

            // Exchange Company token -> Location token
            $ltCh = curl_init('https://services.leadconnectorhq.com/oauth/locationToken');
            curl_setopt_array($ltCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['companyId' => $companyId, 'locationId' => $this->locationId]),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $data['access_token'],
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'Version: 2021-07-28',
                ],
            ]);
            $ltResp = curl_exec($ltCh);
            $ltCode = curl_getinfo($ltCh, CURLINFO_HTTP_CODE);
            curl_close($ltCh);
            $ltData = json_decode($ltResp, true);
            // GHL may return 201 Created for successful token issuance; treat any 2xx as success.
            $ltOk = $ltCode >= 200 && $ltCode < 300;

            if (!$ltOk || empty($ltData['access_token'])) {
                error_log(
                    '[GhlClient] locationToken after refresh failed HTTP '
                    . $ltCode . ': '
                    . self::redactGhlResponseForLog((string)$ltResp)
                );
                throw new \Exception("GHL locationToken exchange failed after refresh (HTTP {$ltCode})");
            }

            // Override the payload to use the Location token (but keep the Company refresh_token)
            $data['access_token'] = $ltData['access_token'];
            $data['expires_in']   = $ltData['expires_in'] ?? 86400;
        }

        $expires        = (int) ($data['expires_in'] ?? 0);
        $expiresAtUnix  = time() + $expires;

        $updateData = [
            'access_token'  => $data['access_token'] ?? null,
            // Preserve current refresh token when provider omits a new one.
            'refresh_token' => $data['refresh_token'] ?? ($this->integration['refresh_token'] ?? null),
            'expires_at'    => $expiresAtUnix,
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            'raw_refresh'   => $data,
            'client_id'     => $clientId,   // Ensures credential routing field is always fresh
            'appId'         => $clientId,   // Backward compat with callback-written docs
        ];

        if ($isBulkProvisioned && $companyId) {
            $updateData['provisioned_from_bulk'] = true;
            $updateData['companyId'] = $companyId;
        }

        // Write back to Firestore (ghl_tokens collection)
        $this->db->collection('ghl_tokens')->document($docId)->set($updateData, ['merge' => true]);

        // Update local state so the caller has the new token immediately
        $this->integration['access_token']  = $data['access_token'] ?? null;
        $this->integration['refresh_token'] = $updateData['refresh_token'];
        $this->integration['expires_at']    = $expiresAtUnix;

        // Invalidate the in-memory/file cache so next request re-reads from Firestore
        $this->clearCache();
    }
}
