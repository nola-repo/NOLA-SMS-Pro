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
                    return [
                        'status' => 401,
                        'body'   => json_encode([
                            'error'   => 'Token refresh failed',
                            'details' => $e->getMessage(),
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
        $cacheTTL = 3600; // Increase token cache to 1 hour (refreshes still happen on 401)

        // 1. Check local file cache first (reduce Firestore reads)
        $cachedData = $cache->get($cacheKey, $cacheTTL);
        if ($cachedData) {
            return $cachedData;
        }

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
            foreach ($query as $doc) {
                if ($doc->exists()) {
                    $data = $doc->data();
                    $data['firestore_doc_id'] = $doc->id();
                    break;
                }
            }
        }

        // 3. Save to cache if found
        if ($data) {
            $cache->set($cacheKey, $data);
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
        // 1. Identify which app are we refreshing (Sub-account vs Agency)
        $ghlApps = [
            'subaccount' => [
                'clientId'     => getenv('GHL_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3',
                'clientSecret' => getenv('GHL_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322',
            ],
            'agency' => [
                'clientId'     => getenv('GHL_AGENCY_CLIENT_ID') ?: '69cb813b4b007d172f7e7a35-mneicksx',
                'clientSecret' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: 'f2c52910-fa01-47b1-9cf7-d812464fe2ad',
            ]
        ];

        $appId = $this->integration['appId'] ?? null;
        $clientId = null;
        $clientSecret = null;

        if ($appId) {
            foreach ($ghlApps as $app) {
                if ($app['clientId'] === $appId) {
                    $clientId = $app['clientId'];
                    $clientSecret = $app['clientSecret'];
                    break;
                }
            }
        }

        // Fallback to legacy env vars if appId is missing or not found in map
        if (!$clientId || !$clientSecret) {
            $clientId     = getenv('GHL_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
            $clientSecret = getenv('GHL_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';
        }

        $refreshToken = $this->integration['refresh_token'] ?? null;
        $docId        = $this->integration['firestore_doc_id'] ?? $this->locationId;

        if (!$clientId || !$clientSecret || !$refreshToken) {
            $missing = [];
            if (!$clientId)     $missing[] = 'GHL_CLIENT_ID';
            if (!$clientSecret) $missing[] = 'GHL_CLIENT_SECRET';
            if (!$refreshToken) $missing[] = 'refresh_token (in Firestore)';

            throw new \Exception('GHL Refresh Error: Missing ' . implode(', ', $missing));
        }

        $tokenUrl = 'https://services.leadconnectorhq.com/oauth/token';
        $postData = [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'user_type'     => ($this->integration['userType'] ?? 'Location'),
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
                'Version: 2021-07-28',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data)) {
            throw new \Exception(
                "GHL token refresh failed with code {$httpCode}: "
                . ($data['error_description'] ?? $response)
            );
        }

        $now            = new \DateTimeImmutable();
        $expires        = (int) ($data['expires_in'] ?? 0);
        $expiresAtUnix  = time() + $expires;

        $updateData = [
            'access_token'  => $data['access_token'] ?? null,
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at'    => $expiresAtUnix,
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            'raw_refresh'   => $data,
        ];

        // Write back to Firestore
        $this->db->collection('ghl_tokens')->document($docId)->set($updateData, ['merge' => true]);

        // Update local state so the caller has the new token immediately
        $this->integration['access_token']  = $data['access_token'] ?? null;
        $this->integration['refresh_token'] = $data['refresh_token'] ?? null;
        $this->integration['expires_at']    = $expiresAtUnix;

        // Update local file cache immediately
        $cacheDir = __DIR__ . '/../cache/tokens';
        $cacheFile = $cacheDir . '/token_' . preg_replace('/[^a-zA-Z0-9_\-]/', '', $this->locationId) . '.json';
        file_put_contents($cacheFile, json_encode($this->integration));
    }
}
