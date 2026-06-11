<?php

/**
 * Shared install/reinstall helpers for the GHL Marketplace flow.
 *
 * Keep the callback, registration page, login page, and account API aligned on
 * what the selected GHL location is and whether it is installed vs registered.
 */

if (!defined('INSTALL_STATE_FRESH_INSTALL')) {
    define('INSTALL_STATE_FRESH_INSTALL', 'FRESH_INSTALL');
    define('INSTALL_STATE_TOKEN_ONLY', 'TOKEN_ONLY');
    define('INSTALL_STATE_LINKED_ACCOUNT', 'LINKED_ACCOUNT');
    define('INSTALL_STATE_AMBIGUOUS', 'AMBIGUOUS');
    define('INSTALL_STATE_SELECTION_REQUIRED', 'SELECTION_REQUIRED');
    define('INSTALL_STATE_COMPANY_MISMATCH', 'COMPANY_MISMATCH');
    define('INSTALL_STATE_PENDING_OAUTH', 'PENDING_OAUTH');
    define('INSTALL_STATE_INSTALLED', 'INSTALLED');
    define('INSTALL_STATE_UNINSTALLED', 'UNINSTALLED');
    define('INSTALL_STATE_INSTALL_PENDING', 'INSTALL_PENDING');
    define('INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION', 'EXACT_SINGLE_LOCATION');
}

/**
 * Whether a ghl_tokens document represents an active Marketplace install (SMS allowed).
 *
 * @param array<string,mixed> $tokenData
 */
function install_token_active_for_sms(bool $docExists, array $tokenData = []): bool
{
    if (!$docExists) {
        return false;
    }

    $state = (string)($tokenData['install_state'] ?? '');
    if ($state === INSTALL_STATE_UNINSTALLED) {
        return false;
    }
    if ($state === INSTALL_STATE_PENDING_OAUTH) {
        return false;
    }
    if (array_key_exists('is_live', $tokenData) && $tokenData['is_live'] === false) {
        return false;
    }

    return true;
}

/**
 * Gate SMS / conversation-provider traffic for a GHL location.
 *
 * @return array{allowed:bool,code:string,reason:string}
 */
function install_location_sms_gate($db, string $locationId): array
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return [
            'allowed' => false,
            'code' => 'missing_location',
            'reason' => 'Missing location',
        ];
    }

    try {
        $snap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    } catch (Exception $e) {
        error_log('[install_helpers] install_location_sms_gate failed for ' . $locationId . ': ' . $e->getMessage());

        return [
            'allowed' => false,
            'code' => 'token_lookup_error',
            'reason' => 'Could not verify install status for this sub-account.',
        ];
    }

    if (!$snap->exists()) {
        return [
            'allowed' => false,
            'code' => 'not_installed',
            'reason' => 'NOLA SMS Pro is not installed for this sub-account. Install from the GoHighLevel Marketplace to send SMS.',
        ];
    }

    $data = $snap->data();
    if (!install_token_active_for_sms(true, $data)) {
        $state = (string)($data['install_state'] ?? '');
        if ($state === INSTALL_STATE_UNINSTALLED || (($data['is_live'] ?? null) === false)) {
            return [
                'allowed' => false,
                'code' => 'app_uninstalled',
                'reason' => 'NOLA SMS Pro was uninstalled for this sub-account. Reinstall from the GoHighLevel Marketplace to use SMS again.',
            ];
        }
        if ($state === INSTALL_STATE_PENDING_OAUTH) {
            return [
                'allowed' => false,
                'code' => 'install_pending',
                'reason' => 'Install is still pending. Complete the Marketplace install for this sub-account.',
            ];
        }

        return [
            'allowed' => false,
            'code' => 'not_active',
            'reason' => 'NOLA SMS Pro is not active for this sub-account.',
        ];
    }

    return ['allowed' => true, 'code' => 'ok', 'reason' => ''];
}

/**
 * Mark a location as uninstalled (blocks ghl_provider, send_sms, agency installed lists).
 */
function install_mark_location_uninstalled(
    $db,
    string $locationId,
    string $source = 'app_uninstall',
    ?string $companyId = null
): bool {
    $locationId = install_clean_location_id($locationId) ?? trim($locationId);
    if ($locationId === '') {
        return false;
    }

    $now = new \Google\Cloud\Core\Timestamp(new DateTimeImmutable());
    $tokenRef = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();

    if (!$tokenSnap->exists()) {
        error_log("[install_helpers] uninstall skip: no ghl_tokens doc for {$locationId}");

        return false;
    }

    $existing = $tokenSnap->data();
    $tokenUpdate = [
        'install_state' => INSTALL_STATE_UNINSTALLED,
        'install_status' => INSTALL_STATE_UNINSTALLED,
        'is_live' => false,
        'toggle_enabled' => false,
        'uninstalled_at' => $now,
        'uninstall_source' => $source,
        'updated_at' => $now,
        'access_token' => null,
        'refresh_token' => null,
    ];
    if ($companyId !== null && $companyId !== '') {
        $tokenUpdate['companyId'] = $companyId;
    }

    $tokenRef->set($tokenUpdate, ['merge' => true]);

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    $db->collection('integrations')->document($intDocId)->set([
        'location_id' => $locationId,
        'install_state' => INSTALL_STATE_UNINSTALLED,
        'is_live' => false,
        'uninstalled_at' => $now,
        'uninstall_source' => $source,
        'updated_at' => $now,
        'access_token' => null,
        'refresh_token' => null,
    ], ['merge' => true]);

    $subRef = $db->collection('agency_subaccounts')->document($locationId);
    if ($subRef->snapshot()->exists()) {
        $subRef->set([
            'toggle_enabled' => false,
            'install_state' => INSTALL_STATE_UNINSTALLED,
            'uninstalled_at' => $now,
            'updated_at' => $now,
        ], ['merge' => true]);
    }

    error_log('[install_helpers] marked uninstalled location=' . $locationId . ' source=' . $source . ' company=' . ($companyId ?? ''));

    return true;
}

/**
 * Mark all location-level tokens under a company as uninstalled (agency-level uninstall).
 */
function install_mark_company_locations_uninstalled($db, string $companyId, string $source = 'app_uninstall_agency'): int
{
    $companyId = trim($companyId);
    if ($companyId === '') {
        return 0;
    }

    $count = 0;
    try {
        $docs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            $locId = (string)($data['locationId'] ?? $data['location_id'] ?? $doc->id());
            $isAgency = ($data['appType'] ?? '') === 'agency' || $locId === $companyId || $doc->id() === $companyId;
            if ($isAgency) {
                if ($doc->id() === $companyId || $locId === $companyId) {
                    $doc->reference()->set([
                        'install_state' => INSTALL_STATE_UNINSTALLED,
                        'is_live' => false,
                        'uninstalled_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
                        'uninstall_source' => $source,
                        'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
                        'access_token' => null,
                        'refresh_token' => null,
                    ], ['merge' => true]);
                }
                continue;
            }
            if (install_mark_location_uninstalled($db, $locId, $source, $companyId)) {
                $count++;
            }
        }
    } catch (Exception $e) {
        error_log('[install_helpers] company uninstall failed for ' . $companyId . ': ' . $e->getMessage());
    }

    return $count;
}

/**
 * True when payload is GHL Marketplace AppInstall / AppUninstall (not SMS).
 *
 * @param array<string,mixed> $payload
 */
function install_is_marketplace_lifecycle_payload(array $payload): bool
{
    $eventType = strtoupper(trim((string)($payload['type'] ?? '')));

    return in_array($eventType, ['UNINSTALL', 'INSTALL'], true);
}

/**
 * Handle AppInstall / AppUninstall webhook body. Returns HTTP status + JSON body.
 *
 * @param array<string,mixed> $payload
 * @param array<string,mixed> $config Optional webhook config (GHL_CLIENT_ID keys)
 * @return array{status:int,body:array<string,mixed>}
 */
function install_handle_marketplace_webhook($db, array $payload, array $config = []): array
{
    $eventType = strtoupper(trim((string)($payload['type'] ?? '')));
    $appId = trim((string)($payload['appId'] ?? $payload['app_id'] ?? ''));
    $locationId = install_clean_location_id($payload['locationId'] ?? $payload['location_id'] ?? null);
    $companyId = install_clean_location_id($payload['companyId'] ?? $payload['company_id'] ?? null);

    $expectedAppIds = array_values(array_filter([
        trim((string)($config['GHL_CLIENT_ID'] ?? '')),
        trim((string)($config['GHL_AGENCY_CLIENT_ID'] ?? '')),
        trim((string)(getenv('GHL_CLIENT_ID') ?: '')),
        trim((string)(getenv('GHL_AGENCY_CLIENT_ID') ?: '')),
    ]));

    if ($appId !== '' && $expectedAppIds !== [] && !in_array($appId, $expectedAppIds, true)) {
        // Keep strict filtering for UNINSTALL, but do not drop INSTALL picks:
        // some tenants send app identifiers that differ from env client IDs.
        if ($eventType !== 'INSTALL') {
            error_log('[install_marketplace_webhook] ignored appId=' . $appId);

            return [
                'status' => 200,
                'body' => ['success' => true, 'ignored' => true, 'reason' => 'unknown_app_id'],
            ];
        }

        error_log('[install_marketplace_webhook] install appId mismatch accepted for preselect capture appId=' . $appId);
    }

    error_log('[install_marketplace_webhook] ' . json_encode([
        'type' => $eventType,
        'appId' => $appId,
        'locationId' => $locationId,
        'companyId' => $companyId,
    ]));

    if ($eventType === 'UNINSTALL') {
        $marked = 0;
        if ($locationId) {
            if (install_mark_location_uninstalled($db, $locationId, 'ghl_app_uninstall_webhook', $companyId)) {
                $marked = 1;
            }
        } elseif ($companyId) {
            $marked = install_mark_company_locations_uninstalled($db, $companyId, 'ghl_app_uninstall_agency_webhook');
        } else {
            return [
                'status' => 400,
                'body' => ['success' => false, 'error' => 'UNINSTALL missing locationId and companyId'],
            ];
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'event' => 'UNINSTALL',
                'locations_marked' => $marked,
                'locationId' => $locationId,
                'companyId' => $companyId,
            ],
        ];
    }

    if ($eventType === 'INSTALL') {
        if ($locationId !== null && $companyId !== null) {
            $userId = isset($payload['userId']) ? trim((string)$payload['userId']) : (isset($payload['user_id']) ? trim((string)$payload['user_id']) : null);
            $installationId = isset($payload['installationId']) ? trim((string)$payload['installationId']) : (isset($payload['installation_id']) ? trim((string)$payload['installation_id']) : null);
            install_record_marketplace_install_pick($db, $companyId, $locationId, $userId, $installationId);
        }

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'event' => 'INSTALL',
                'message' => 'Acknowledged; complete OAuth via /oauth/callback for token persistence.',
                'locationId' => $locationId,
                'companyId' => $companyId,
            ],
        ];
    }

    return [
        'status' => 400,
        'body' => ['success' => false, 'error' => 'Unsupported marketplace event', 'type' => $eventType],
    ];
}

function install_clean_location_id($value): ?string
{
    if (!is_string($value) && !is_numeric($value)) {
        return null;
    }

    $value = trim((string)$value);
    if ($value === '' || strpos($value, '{{') !== false) {
        return null;
    }

    return preg_match('/^[A-Za-z0-9_-]{8,}$/', $value) ? $value : null;
}

function install_unique_ids(array $ids): array
{
    $out = [];
    foreach ($ids as $id) {
        $clean = install_clean_location_id($id);
        if ($clean !== null && !in_array($clean, $out, true)) {
            $out[] = $clean;
        }
    }

    return $out;
}

/**
 * GHL sometimes echoes root `locationId` equal to `companyId` on Company-scoped tokens.
 * That value is not a sub-account id and must not be used for /oauth/locationToken.
 *
 * @param array<string,mixed> $oauthData
 */
function install_sanitize_token_root_location_id_for_company(array $oauthData, $tokenLocationId): ?string
{
    $tl = install_clean_location_id($tokenLocationId);
    if ($tl === null) {
        return null;
    }
    if ((string)($oauthData['userType'] ?? '') !== 'Company') {
        return $tl;
    }
    $cid = install_clean_location_id($oauthData['companyId'] ?? $oauthData['company_id'] ?? null);
    if ($cid !== null && $tl === $cid) {
        return null;
    }

    return $tl;
}

/**
 * Redact secrets before logging OAuth/token payloads (structure stays inspectable).
 *
 * @param array<string,mixed> $data
 * @return array<string,mixed>
 */
function install_redact_oauth_token_log_payload(array $data): array
{
    $redactKeys = ['access_token', 'refresh_token', 'id_token'];
    $out = [];
    foreach ($data as $k => $v) {
        $key = (string)$k;
        if (in_array($key, $redactKeys, true)) {
            if (is_string($v) && $v !== '') {
                $out[$k] = '[REDACTED len=' . strlen($v) . ']';
                continue;
            }
            if (is_array($v)) {
                $out[$k] = install_redact_oauth_token_log_payload($v);
                continue;
            }
        }
        if (is_array($v)) {
            $out[$k] = install_redact_oauth_token_log_payload($v);
            continue;
        }
        $out[$k] = $v;
    }

    return $out;
}

function install_extract_company_name(array $data): string
{
    foreach (['companyName', 'company_name', 'agencyName', 'agency_name'] as $key) {
        if (!isset($data[$key])) {
            continue;
        }

        if (!is_scalar($data[$key])) {
            continue;
        }

        $value = trim((string)$data[$key]);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Fetch agency/company display name from the GHL Companies API.
 */
function install_fetch_company_name_from_ghl(string $companyId, string $accessToken): string
{
    $companyId = trim($companyId);
    $accessToken = trim($accessToken);
    if ($companyId === '' || $accessToken === '') {
        return '';
    }

    try {
        $url = 'https://services.leadconnectorhq.com/companies/' . rawurlencode($companyId);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return '';
        }

        $body = json_decode((string)$response, true);
        return trim((string)($body['company']['name'] ?? ''));
    } catch (\Throwable $e) {
        error_log('[install_fetch_company_name_from_ghl] failed for ' . $companyId . ': ' . $e->getMessage());
        return '';
    }
}

/**
 * Resolve agency/company name from token payload, with optional GHL API fallback.
 */
function install_resolve_company_name(array $data, ?string $companyId = null, ?string $accessToken = null): string
{
    $name = install_extract_company_name($data);
    if ($name !== '') {
        return $name;
    }

    $companyId = trim((string)($companyId ?? ($data['companyId'] ?? $data['company_id'] ?? '')));
    if ($companyId === '') {
        return '';
    }

    foreach (install_company_name_token_candidates($data, $accessToken) as $candidateToken) {
        $fetched = install_fetch_company_name_from_ghl($companyId, $candidateToken);
        if ($fetched !== '') {
            return $fetched;
        }
    }

    return '';
}

/**
 * Candidate bearer tokens for company-name lookup, preferring scopes that include companies.readonly.
 *
 * @return array<int,string>
 */
function install_company_name_token_candidates(array $agencyData, ?string $preferredToken = null): array
{
    $candidates = [];
    $push = static function (?string $token) use (&$candidates): void {
        $token = trim((string)$token);
        if ($token !== '' && !in_array($token, $candidates, true)) {
            $candidates[] = $token;
        }
    };

    $rawRefresh = $agencyData['raw_refresh'] ?? null;
    if (is_array($rawRefresh)) {
        $push($rawRefresh['access_token'] ?? null);
    }

    $push($preferredToken ?? null);
    $push($agencyData['access_token'] ?? null);

    $raw = $agencyData['raw'] ?? null;
    if (is_array($raw)) {
        $push($raw['access_token'] ?? null);
    }

    $refreshToken = trim((string)($rawRefresh['refresh_token'] ?? $agencyData['refresh_token'] ?? ''));
    if ($refreshToken !== '') {
        $agencyClientId = trim((string)(getenv('GHL_AGENCY_CLIENT_ID') ?: ''));
        $agencySecret = trim((string)(getenv('GHL_AGENCY_CLIENT_SECRET') ?: ''));
        if ($agencyClientId !== '' && $agencySecret !== '') {
            try {
                $ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_POST => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 10,
                    CURLOPT_POSTFIELDS => http_build_query([
                        'client_id' => $agencyClientId,
                        'client_secret' => $agencySecret,
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $refreshToken,
                        'user_type' => 'Company',
                    ]),
                    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Version: 2021-07-28'],
                ]);
                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && is_string($response)) {
                    $body = json_decode($response, true);
                    $push(is_array($body) ? ($body['access_token'] ?? null) : null);
                }
            } catch (\Throwable $e) {
                error_log('[install_company_name_token_candidates] refresh failed: ' . $e->getMessage());
            }
        }
    }

    return $candidates;
}

/**
 * Resolve agency display name from a ghl_tokens/{companyId} document.
 */
function install_resolve_agency_name_from_token_doc(array $agencyData, string $companyId): string
{
    $existing = trim((string)(
        $agencyData['agency_name']
        ?? $agencyData['company_name']
        ?? $agencyData['companyName']
        ?? $agencyData['location_name']
        ?? ''
    ));
    if ($existing !== '') {
        return $existing;
    }

    return install_resolve_company_name($agencyData, $companyId);
}

/**
 * Merge OAuth token `locations` with a single nested `location` object when present.
 *
 * @return array<int, mixed>
 */
function install_oauth_locations_array_for_resolver(array $data): array
{
    $out = [];
    if (!empty($data['location']) && is_array($data['location'])) {
        $out[] = $data['location'];
    }
    if (!empty($data['locations']) && is_array($data['locations'])) {
        foreach ($data['locations'] as $row) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * Marketplace / chooser may return the pick as selectedLocationId or nested location{}.
 */
function install_oauth_marketplace_selected_location_id(array $data): ?string
{
    $companyId = install_clean_location_id($data['companyId'] ?? $data['company_id'] ?? null);
    $isCompanyToken = ((string)($data['userType'] ?? '')) === 'Company';

    $pick = static function (?string $id) use ($companyId, $isCompanyToken): ?string {
        $id = install_clean_location_id($id);
        if ($id === null) {
            return null;
        }
        if ($isCompanyToken && $companyId !== null && $id === $companyId) {
            return null;
        }

        return $id;
    };

    foreach ([
        'selectedLocationId',
        'selected_location_id',
        'selectedLocation',
        'selected_location',
        'installedLocationId',
        'installed_location_id',
        'targetLocationId',
        'target_location_id',
    ] as $k) {
        $id = $pick($data[$k] ?? null);
        if ($id !== null) {
            return $id;
        }
    }

    $fromAssoc = $pick(install_location_id_from_assoc_payload($data));
    if ($fromAssoc !== null) {
        return $fromAssoc;
    }

    if (($data['userType'] ?? '') === 'Location') {
        $id = $pick($data['locationId'] ?? $data['location_id'] ?? null);
        if ($id !== null) {
            return $id;
        }
    }

    if (($data['isBulkInstallation'] ?? null) === false) {
        $id = $pick($data['locationId'] ?? $data['location_id'] ?? null);
        if ($id !== null) {
            return $id;
        }
    }

    if (!empty($data['location']) && is_array($data['location'])) {
        $id = $pick(
            $data['location']['id'] ?? $data['location']['locationId'] ?? $data['location']['location_id'] ?? null
        );
        if ($id !== null) {
            return $id;
        }
    }

    if (($data['isBulkInstallation'] ?? null) !== true) {
        $rows = install_location_rows_from_ghl(install_oauth_locations_array_for_resolver($data));
        if (count($rows['ids']) === 1) {
            $id = $pick($rows['ids'][0]);
            if ($id !== null) {
                return $id;
            }
        }
    }

    // Company JWT payloads use authClassId for the agency — never treat as sub-account.
    if (!$isCompanyToken) {
        $jwtLocationId = $pick(install_location_id_from_oauth_access_token((string)($data['access_token'] ?? '')));
        if ($jwtLocationId !== null) {
            return $jwtLocationId;
        }
    }

    return null;
}

/**
 * Best-effort location id from an OAuth access_token JWT payload (unverified decode).
 */
function install_location_id_from_oauth_access_token(?string $accessToken): ?string
{
    if (!is_string($accessToken) || $accessToken === '') {
        return null;
    }

    $parts = explode('.', $accessToken);
    if (count($parts) < 2) {
        return null;
    }

    $payloadSegment = $parts[1];
    $padded = strtr($payloadSegment, '-_', '+/');
    $padLen = strlen($padded) % 4;
    if ($padLen > 0) {
        $padded .= str_repeat('=', 4 - $padLen);
    }
    $decoded = base64_decode($padded, true);
    if ($decoded === false || $decoded === '') {
        return null;
    }

    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        return null;
    }

    foreach (['locationId', 'location_id', 'selectedLocationId', 'selected_location_id'] as $key) {
        $id = install_clean_location_id($payload[$key] ?? null);
        if ($id !== null) {
            return $id;
        }
    }

    $fromAssoc = install_location_id_from_assoc_payload($payload);
    if ($fromAssoc !== null) {
        return $fromAssoc;
    }

    // Last resort for some Location JWTs (never use for Company tokens — callers must gate).
    return install_clean_location_id($payload['authClassId'] ?? null);
}

/**
 * Persist the sub-account chosen in a GHL INSTALL webhook for the OAuth callback to read.
 */
function install_record_marketplace_install_pick($db, ?string $companyId, ?string $locationId, ?string $userId = null, ?string $installationId = null): void
{
    $companyId = install_clean_location_id($companyId);
    $locationId = install_clean_location_id($locationId);
    if ($companyId === null || $locationId === null || $locationId === $companyId) {
        return;
    }

    try {
        $now = new DateTimeImmutable();
        $data = [
            'company_id' => $companyId,
            'location_id' => $locationId,
            'picked_at' => new \Google\Cloud\Core\Timestamp($now),
            'expires_at' => new \Google\Cloud\Core\Timestamp($now->modify('+30 minutes')),
        ];
        if ($userId !== null && $userId !== '') {
            $data['userId'] = $userId;
        }
        if ($installationId !== null && $installationId !== '') {
            $data['installationId'] = $installationId;
        }
        $db->collection('marketplace_install_picks')->document($companyId)->set($data, ['merge' => true]);
    } catch (Exception $e) {
        error_log('[install_helpers] marketplace_install_pick write failed: ' . $e->getMessage());
    }
}

/**
 * Recent INSTALL webhook pick for this agency (chooselocation may not echo it in the token).
 */
function install_recent_marketplace_install_location_id($db, string $companyId): ?string
{
    $companyId = install_clean_location_id($companyId);
    if ($companyId === null) {
        return null;
    }

    try {
        $snap = $db->collection('marketplace_install_picks')->document($companyId)->snapshot();
        if (!$snap->exists()) {
            return null;
        }

        $data = $snap->data();
        $expiresAt = $data['expires_at'] ?? null;
        if ($expiresAt instanceof \Google\Cloud\Core\Timestamp) {
            if ($expiresAt->get()->getTimestamp() < time()) {
                return null;
            }
        }

        // Ensure the installation selection is fresh (within 2 minutes)
        $pickedAt = $data['picked_at'] ?? null;
        if ($pickedAt instanceof \Google\Cloud\Core\Timestamp) {
            $age = time() - $pickedAt->get()->getTimestamp();
            if ($age > 120) {
                return null;
            }
        }

        $loc = install_clean_location_id($data['location_id'] ?? null);
        if ($loc !== null && $loc === $companyId) {
            return null;
        }

        return $loc;
    } catch (Exception $e) {
        error_log('[install_helpers] marketplace_install_pick read failed: ' . $e->getMessage());

        return null;
    }
}

/**
 * Tier-1 signals for the second-step install UI (Marketplace pick → OAuth → selection form).
 *
 * @return array{
 *   session_location_id: ?string,
 *   token_marketplace_selected_id: ?string,
 *   query_location_id: ?string,
 *   state_location_id: ?string,
 *   chooser_callback_pick_id: ?string,
 *   webhook_location_id: ?string,
 *   jwt_location_id: ?string
 * }
 */
function install_collect_preselect_signals(
    $db,
    array $oauthData,
    ?string $state,
    array $query,
    ?string $companyId = null,
    ?string $sessionLocationId = null,
    ?array $oauthTokenLocationCandidateIds = null
): array {
    $companyId = install_clean_location_id($companyId);
    $tokenCand = install_unique_ids(is_array($oauthTokenLocationCandidateIds) ? $oauthTokenLocationCandidateIds : []);

    $chooserCallbackPick = install_chooser_pick_from_oauth_redirect_query($query, $companyId, $tokenCand);
    $tokenMarketplacePick = install_oauth_marketplace_selected_location_id($oauthData);
    $queryPick = install_extract_location_id_from_query($query, $companyId);
    $statePick = install_extract_location_id_from_oauth_state($state);
    $jwtPick = ((string)($oauthData['userType'] ?? '')) !== 'Company'
        ? install_location_id_from_oauth_access_token((string)($oauthData['access_token'] ?? ''))
        : null;
    $cidForJwt = install_clean_location_id($oauthData['companyId'] ?? $oauthData['company_id'] ?? null);
    if ($cidForJwt !== null && install_clean_location_id($jwtPick) === $cidForJwt) {
        $jwtPick = null;
    }
    $jwtPick = install_clean_location_id($jwtPick);

    // Reduce callback latency: this helper is called multiple times per request.
    // Cache webhook pick lookups and only do short retries when no direct signal exists.
    static $webhookPickCache = [];
    $webhookLoc = null;
    if ($companyId !== null) {
        if (array_key_exists($companyId, $webhookPickCache)) {
            $webhookLoc = $webhookPickCache[$companyId];
        } else {
            $hasDirectSignals = $chooserCallbackPick !== null
                || $tokenMarketplacePick !== null
                || $queryPick !== null
                || $statePick !== null
                || $jwtPick !== null;
            $maxAttempts = $hasDirectSignals ? 1 : 5;
            $startPoll = microtime(true);
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $webhookLoc = install_recent_marketplace_install_location_id($db, $companyId);
                if ($webhookLoc !== null) {
                    $elapsed = (int)round((microtime(true) - $startPoll) * 1000);
                    error_log("[install_helpers] Webhook pick found on attempt={$attempt} after {$elapsed}ms: {$webhookLoc}");
                    break;
                }
                if ($attempt < $maxAttempts - 1) {
                    usleep(500000);
                }
            }
            if ($webhookLoc === null && !$hasDirectSignals) {
                $elapsed = (int)round((microtime(true) - $startPoll) * 1000);
                error_log("[install_helpers] Webhook pick NOT found after polling {$maxAttempts} times over {$elapsed}ms");
            }
            $webhookPickCache[$companyId] = $webhookLoc;
        }
    }

    return [
        'session_location_id' => install_clean_location_id($sessionLocationId),
        'chooser_callback_pick_id' => $chooserCallbackPick,
        'token_marketplace_selected_id' => $tokenMarketplacePick,
        'query_location_id' => $queryPick,
        'state_location_id' => $statePick,
        'webhook_location_id' => $webhookLoc,
        'jwt_location_id' => $jwtPick,
    ];
}

/**
 * @return array{ids: array<int,string>, names: array<string,string>}
 */
function install_location_rows_from_ghl($locations): array
{
    $ids = [];
    $names = [];

    if (!is_array($locations)) {
        return ['ids' => [], 'names' => []];
    }

    foreach ($locations as $loc) {
        if (is_string($loc) || is_int($loc) || is_float($loc)) {
            $id = install_clean_location_id($loc);
            if ($id !== null && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
            continue;
        }

        if (!is_array($loc)) {
            continue;
        }

        $id = install_clean_location_id($loc['id'] ?? $loc['locationId'] ?? $loc['location_id'] ?? null);
        if ($id === null) {
            continue;
        }

        if (!in_array($id, $ids, true)) {
            $ids[] = $id;
        }

        $name = trim((string)($loc['name'] ?? $loc['location_name'] ?? $loc['locationName'] ?? ''));
        if ($name !== '') {
            $names[$id] = $name;
        }
    }

    return ['ids' => $ids, 'names' => $names];
}

/**
 * Pull one explicit GHL location id from an associative callback/state payload.
 */
function install_location_id_from_assoc_payload(?array $payload): ?string
{
    if (!$payload) {
        return null;
    }

    foreach (['locationId', 'location_id', 'selected_location_id'] as $key) {
        $clean = install_clean_location_id($payload[$key] ?? null);
        if ($clean !== null) {
            return $clean;
        }
    }

    foreach (['location_ids', 'locationIds', 'approvedLocations', 'approvedLocationIds'] as $listKey) {
        $value = $payload[$listKey] ?? null;
        $clean = install_clean_location_id($value);
        if ($clean !== null) {
            return $clean;
        }
        if (is_array($value) && count($value) === 1) {
            $clean = install_clean_location_id($value[0] ?? null);
            if ($clean !== null) {
                return $clean;
            }
            if (is_array($value[0] ?? null)) {
                $nested = install_location_id_from_assoc_payload($value[0]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
    }

    $locations = $payload['locations'] ?? null;
    if (is_array($locations) && count($locations) === 1 && is_array($locations[0] ?? null)) {
        $clean = install_clean_location_id($locations[0]['id'] ?? $locations[0]['locationId'] ?? $locations[0]['location_id'] ?? null);
        if ($clean !== null) {
            return $clean;
        }
    }

    foreach (['location', 'selectedLocation', 'subaccount', 'subAccount', 'context'] as $nestedKey) {
        if (isset($payload[$nestedKey]) && is_array($payload[$nestedKey])) {
            $nested = install_location_id_from_assoc_payload($payload[$nestedKey]);
            if ($nested !== null) {
                return $nested;
            }
        }
    }

    return null;
}

/**
 * Normalize location IDs from mixed callback payload formats.
 *
 * @return array<int,string>
 */
function install_extract_location_ids_from_mixed($value): array
{
    $ids = [];
    $walk = null;
    $walk = static function ($candidate) use (&$ids, &$walk): void {
        $clean = install_clean_location_id($candidate);
        if ($clean !== null) {
            $ids[] = $clean;
            return;
        }

        if (!is_array($candidate)) {
            return;
        }

        foreach (['id', 'locationId', 'location_id', 'selected_location_id'] as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }
            $clean = install_clean_location_id($candidate[$key]);
            if ($clean !== null) {
                $ids[] = $clean;
            }
        }

        foreach (['locations', 'approvedLocations', 'approvedLocationIds', 'locationIds', 'location_ids', 'location', 'selectedLocation', 'subaccount', 'subAccount', 'context'] as $key) {
            if (isset($candidate[$key]) && is_array($candidate[$key])) {
                foreach ($candidate[$key] as $nested) {
                    $walk($nested);
                }
            }
        }

        $keys = array_keys($candidate);
        if ($keys === range(0, count($candidate) - 1)) {
            foreach ($candidate as $item) {
                $walk($item);
            }
        }
    };

    if (is_array($value)) {
        $walk($value);
    } elseif (is_string($value) && trim($value) !== '') {
        $raw = trim($value);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $walk($decoded);
        } else {
            foreach (array_map('trim', explode(',', $raw)) as $item) {
                $walk($item);
            }
        }
    }

    return install_unique_ids($ids);
}

function install_extract_location_id_from_query(array $query, ?string $rejectCompanyId = null): ?string
{
    $reject = install_clean_location_id($rejectCompanyId);

    $asSubLoc = static function (?string $id) use ($reject): ?string {
        $clean = install_clean_location_id($id);
        if ($clean === null) {
            return null;
        }
        if ($reject !== null && $clean === $reject) {
            return null;
        }

        return $clean;
    };

    foreach ([
        'locationId',
        'location_id',
        'selected_location_id',
        'selectedLocationId',
        'selectedLocation',
        'selected_location',
    ] as $key) {
        $v = $asSubLoc($query[$key] ?? null);
        if ($v !== null) {
            return $v;
        }
    }

    $approvedIds = install_unique_ids(array_merge(
        install_extract_location_ids_from_mixed($query['approvedLocations'] ?? null),
        install_extract_location_ids_from_mixed($query['approvedLocationIds'] ?? null)
    ));
    if ($reject !== null) {
        $approvedIds = array_values(array_filter($approvedIds, static function ($id) use ($reject) {
            return $id !== $reject;
        }));
    }
    if (count($approvedIds) === 1) {
        return $approvedIds[0];
    }

    return null;
}

/**
 * Sub-account the user chose in GHL chooser, from OAuth redirect query params,
 * when the token lists many locations. Compares GET approved or locations ids to token locations[].
 *
 * @param array<int,string> $oauthTokenLocationIds ids parsed from OAuth token locations[]
 */
function install_chooser_pick_from_oauth_redirect_query(array $query, ?string $companyId, array $oauthTokenLocationIds): ?string
{
    $cid = install_clean_location_id($companyId);
    $oauthTokenLocationIds = install_unique_ids($oauthTokenLocationIds);

    $fromQuery = install_unique_ids(array_merge(
        install_extract_location_ids_from_mixed($query['approvedLocations'] ?? null),
        install_extract_location_ids_from_mixed($query['approvedLocationIds'] ?? null),
        install_extract_location_ids_from_mixed($query['locations'] ?? null),
        install_extract_location_ids_from_mixed($query['locationIds'] ?? null),
        install_extract_location_ids_from_mixed($query['location_ids'] ?? null),
    ));

    $filtered = [];
    foreach ($fromQuery as $id) {
        if ($cid !== null && $id === $cid) {
            continue;
        }
        if ($oauthTokenLocationIds !== [] && !in_array($id, $oauthTokenLocationIds, true)) {
            continue;
        }
        $filtered[] = $id;
    }
    $filtered = install_unique_ids($filtered);
    if (count($filtered) === 1) {
        return $filtered[0];
    }

    if ($oauthTokenLocationIds !== []) {
        $intersect = install_unique_ids(array_values(array_intersect($fromQuery, $oauthTokenLocationIds)));
        if ($cid !== null) {
            $intersect = array_values(array_filter($intersect, static function ($id) use ($cid) {
                return $id !== $cid;
            }));
        }
        if (count($intersect) === 1) {
            return $intersect[0];
        }
    }

    return null;
}

function install_extract_location_id_from_oauth_state(?string $state, bool $allowPlainId = false): ?string
{
    if (!$state) {
        return null;
    }

    $candidates = [$state];
    $decoded = urldecode($state);
    if ($decoded !== $state) {
        $candidates[] = $decoded;
    }

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $trimmed = trim($candidate);

        $parsed = [];
        parse_str($trimmed, $parsed);
        if (!empty($parsed)) {
            $fromQuery = install_location_id_from_assoc_payload($parsed);
            if ($fromQuery !== null) {
                return $fromQuery;
            }
        }

        $json = json_decode($trimmed, true);
        if (is_array($json)) {
            $fromJson = install_location_id_from_assoc_payload($json);
            if ($fromJson !== null) {
                return $fromJson;
            }
        }

        foreach ([$trimmed, strtr($trimmed, '-_', '+/')] as $encoded) {
            $padded = $encoded;
            $padLen = strlen($padded) % 4;
            if ($padLen > 0) {
                $padded .= str_repeat('=', 4 - $padLen);
            }
            $decodedPayload = base64_decode($padded, true);
            if ($decodedPayload === false || $decodedPayload === '') {
                continue;
            }
            $decodedJson = json_decode($decodedPayload, true);
            if (is_array($decodedJson)) {
                $fromEncodedJson = install_location_id_from_assoc_payload($decodedJson);
                if ($fromEncodedJson !== null) {
                    return $fromEncodedJson;
                }
            }
        }

        if (preg_match('/(?:locationId|location_id|selected_location_id)["=: ]+([A-Za-z0-9_-]{8,})/i', $trimmed, $m)) {
            return $m[1];
        }

        if ($allowPlainId) {
            $clean = install_clean_location_id($trimmed);
            if ($clean !== null) {
                return $clean;
            }
        }
    }

    return null;
}

/**
 * Resolve the one selected subaccount from trusted GHL callback signals.
 *
 * Registration state is never a selection signal; only explicit GHL/OAuth or
 * signed install-session signals are allowed to choose a location.
 *
 * @return array{
 *   ok: bool,
 *   location_id: ?string,
 *   source: string,
 *   reason: string,
 *   candidate_ids: array<int,string>,
 *   location_names: array<string,string>,
 *   conflict?: array<string,mixed>
 * }
 */
function install_resolve_selected_location(array $signals): array
{
    $rows = install_location_rows_from_ghl($signals['locations'] ?? []);
    $locationIdsFromRows = $rows['ids'];
    $locationNames = $rows['names'];

    $locationIdsFromMixed = install_extract_location_ids_from_mixed($signals['locations'] ?? null);
    $locationIdsUnique = install_unique_ids(array_merge($locationIdsFromRows, $locationIdsFromMixed));

    $approvedSignalIds = install_unique_ids(
        is_array($signals['approved_location_ids'] ?? null) ? $signals['approved_location_ids'] : []
    );
    $queryApprovedSignalIds = install_unique_ids(
        is_array($signals['query_approved_location_ids'] ?? null) ? $signals['query_approved_location_ids'] : []
    );
    $approvedMerged = install_unique_ids(array_merge($approvedSignalIds, $queryApprovedSignalIds));

    $tokenLocationId = install_clean_location_id($signals['token_location_id'] ?? null);
    $queryLocationId = install_clean_location_id($signals['query_location_id'] ?? null);
    $stateLocationId = install_clean_location_id($signals['state_location_id'] ?? null);
    $sessionLocationId = install_clean_location_id($signals['session_location_id'] ?? null);
    $marketplaceSelectedId = install_clean_location_id($signals['token_marketplace_selected_id'] ?? null);

    // Priority: signed session > marketplace picker > query > OAuth state
    // > merged approvedLocations (unique) > locations[] unique > token root locationId.
    $tier1Order = [
        'signed_install_session' => $sessionLocationId,
        'ghl_token_marketplace_selected' => $marketplaceSelectedId,
        'query_location_field' => $queryLocationId,
        'oauth_state' => $stateLocationId,
    ];
    $tier1SourcesById = [];
    $tier1Ids = [];
    foreach ($tier1Order as $source => $id) {
        if ($id === null) {
            continue;
        }
        $tier1Ids[] = $id;
        if (!isset($tier1SourcesById[$id])) {
            $tier1SourcesById[$id] = [];
        }
        if (!in_array($source, $tier1SourcesById[$id], true)) {
            $tier1SourcesById[$id][] = $source;
        }
    }
    $tier1Unique = install_unique_ids($tier1Ids);

    $candidateIds = install_unique_ids(array_merge(
        $tier1Ids,
        $approvedMerged,
        $locationIdsUnique,
        $tokenLocationId !== null ? [$tokenLocationId] : []
    ));

    if (count($tier1Unique) > 1) {
        $tier1ConflictPriority = [
            ['signed_install_session', $sessionLocationId],
            ['ghl_token_marketplace_selected', $marketplaceSelectedId],
            ['query_location_field', $queryLocationId],
            ['oauth_state', $stateLocationId],
        ];
        foreach ($tier1ConflictPriority as $priorityRow) {
            $prioritySource = $priorityRow[0];
            $priorityId = install_clean_location_id($priorityRow[1] ?? null);
            if ($priorityId === null || !in_array($priorityId, $tier1Unique, true)) {
                continue;
            }

            return [
                'ok' => true,
                'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
                'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
                'locationId' => $priorityId,
                'location_id' => $priorityId,
                'source' => $prioritySource,
                'reason' => 'tier1_conflict_resolved_by_priority',
                'candidate_ids' => $candidateIds,
                'location_names' => $locationNames,
                'conflict' => $tier1SourcesById,
            ];
        }

        return [
            'ok' => false,
            'status' => INSTALL_STATE_AMBIGUOUS,
            'resolutionMode' => INSTALL_STATE_AMBIGUOUS,
            'locationId' => null,
            'location_id' => null,
            'source' => 'conflicting_exact_signals',
            'reason' => 'conflicting_exact_location_signals',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
            'conflict' => $tier1SourcesById,
        ];
    }

    if (count($tier1Unique) === 1) {
        $locationId = $tier1Unique[0];

        return [
            'ok' => true,
            'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'locationId' => $locationId,
            'location_id' => $locationId,
            'source' => implode('+', $tier1SourcesById[$locationId] ?? ['tier1']),
            'reason' => 'exact_single_location',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    if (count($approvedMerged) === 1) {
        $locationId = $approvedMerged[0];

        return [
            'ok' => true,
            'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'locationId' => $locationId,
            'location_id' => $locationId,
            'source' => 'approved_locations_unique_single',
            'reason' => 'exact_single_location',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    if (count($locationIdsUnique) === 1) {
        $locationId = $locationIdsUnique[0];

        return [
            'ok' => true,
            'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'locationId' => $locationId,
            'location_id' => $locationId,
            'source' => 'locations_unique_single',
            'reason' => 'exact_single_location',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    if ($tokenLocationId !== null) {
        return [
            'ok' => true,
            'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
            'locationId' => $tokenLocationId,
            'location_id' => $tokenLocationId,
            'source' => 'token_location_field',
            'reason' => 'exact_single_location',
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
        ];
    }

    $pendingStatus = count($candidateIds) > 1 ? INSTALL_STATE_AMBIGUOUS : INSTALL_STATE_SELECTION_REQUIRED;
    return [
        'ok' => false,
        'status' => $pendingStatus,
        'resolutionMode' => $pendingStatus,
        'locationId' => null,
        'location_id' => null,
        'source' => 'unresolved',
        'reason' => count($candidateIds) > 1 ? 'ambiguous_location_candidates' : 'no_location_signal',
        'candidate_ids' => $candidateIds,
        'location_names' => $locationNames,
    ];
}

function install_final_install_checkpoint(?string $locationId, string $resolutionMode): array
{
    $locationId = install_clean_location_id($locationId);
    if (!$locationId || $resolutionMode !== INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION) {
        return [
            'ok' => false,
            'status' => INSTALL_STATE_INSTALL_PENDING,
            'message' => 'OAuth not fully resolved',
        ];
    }

    return [
        'ok' => true,
        'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        'locationId' => $locationId,
        'message' => 'OAuth fully resolved',
    ];
}

function install_summarize_classification(array $classification): array
{
    return [
        'status' => (string)($classification['status'] ?? ''),
        'token_exists' => (bool)($classification['token_exists'] ?? false),
        'linked' => (bool)($classification['linked'] ?? false),
        'mismatch' => (bool)($classification['mismatch'] ?? false),
    ];
}

/**
 * Marketplace / agency chooser pick (tier-1 signals only) for the second-step UI.
 */
function install_preselected_location_for_selection_ui(array $signals): ?string
{
    foreach ([
        'session_location_id',
        'chooser_callback_pick_id',
        'webhook_location_id',
        'token_marketplace_selected_id',
        'query_location_id',
        'state_location_id',
        'jwt_location_id',
    ] as $key) {
        $id = install_clean_location_id($signals[$key] ?? null);
        if ($id !== null) {
            return $id;
        }
    }

    return null;
}

/**
 * Canonical initial subaccount credit_balance for users/{id} (legacy integrations fallback).
 */
function users_resolve_initial_credit_balance_for_location($db, string $locationId): int
{
    $locationId = install_clean_location_id($locationId) ?? trim($locationId);
    if ($locationId === '') {
        return 0;
    }

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    try {
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        if (!$intSnap->exists()) {
            return 0;
        }

        $intData = $intSnap->data();
        if (array_key_exists('credit_balance', $intData) && is_numeric($intData['credit_balance'])) {
            return max(0, (int)$intData['credit_balance']);
        }
    } catch (Exception $e) {
        error_log('[install_helpers] users_resolve_initial_credit_balance failed for ' . $locationId . ': ' . $e->getMessage());
    }

    return 0;
}

/**
 * When the user already picked a sub-account in GHL, show only that workspace on step 2.
 *
 * @param array<int,string> $candidateIds
 * @param array<string,string> $locationNames
 * @return array{candidate_ids: array<int,string>, location_names: array<string,string>, ui_mode: string, preselected_location_id: ?string}
 */
function install_narrow_selection_to_preselected(array $candidateIds, array $locationNames, ?string $preselectedId): array
{
    $candidateIds = install_unique_ids($candidateIds);
    $preselectedId = install_clean_location_id($preselectedId);
    if ($preselectedId === null) {
        return [
            'candidate_ids' => $candidateIds,
            'location_names' => $locationNames,
            'ui_mode' => 'list',
            'preselected_location_id' => null,
        ];
    }

    if (!in_array($preselectedId, $candidateIds, true)) {
        $candidateIds[] = $preselectedId;
    }

    return [
        'candidate_ids' => [$preselectedId],
        'location_names' => $locationNames,
        'ui_mode' => 'confirm_preselected',
        'preselected_location_id' => $preselectedId,
    ];
}

/**
 * When GHL Marketplace chooser already picked a sub-account, skip the second picker.
 */
function install_trust_marketplace_preselect_to_resolution(array $resolution, ?string $preselectedId, ?string $oauthCompanyId = null): array
{
    $preselectedId = install_clean_location_id($preselectedId);
    if ($preselectedId === null || !empty($resolution['ok'])) {
        return $resolution;
    }

    $cid = install_clean_location_id($oauthCompanyId);
    if ($cid !== null && $preselectedId === $cid) {
        error_log('[install_helpers] trust_marketplace_preselect skipped: preselected matches company id');

        return $resolution;
    }

    $candidateIds = install_unique_ids(
        is_array($resolution['candidate_ids'] ?? null) ? $resolution['candidate_ids'] : []
    );
    if (!in_array($preselectedId, $candidateIds, true)) {
        $candidateIds[] = $preselectedId;
    }
    $locationNames = is_array($resolution['location_names'] ?? null) ? $resolution['location_names'] : [];

    return [
        'ok' => true,
        'status' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        'resolutionMode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        'locationId' => $preselectedId,
        'location_id' => $preselectedId,
        'source' => 'marketplace_preselected_trusted',
        'reason' => 'trusted_marketplace_picker',
        'candidate_ids' => $candidateIds,
        'location_names' => $locationNames,
    ];
}

/**
 * Defer INSTALLED / is_live until NOLA registration completes (fresh or token-only installs).
 */
function install_should_defer_finalize_for_decision(array $decision): bool
{
    return ((string)($decision['kind'] ?? '')) === 'register';
}

function install_maybe_finalize_location_install(
    $db,
    string $locationId,
    array $decision,
    string $resolutionMode,
    string $resolutionSource,
    DateTimeImmutable $now,
    ?array $preloadedTokenData = null
): void {
    if (install_should_defer_finalize_for_decision($decision)) {
        return;
    }

    install_finalize_location_install(
        $db,
        $locationId,
        $decision,
        $resolutionMode,
        $resolutionSource,
        $now,
        $preloadedTokenData
    );
}

/**
 * Mark ghl_tokens + integrations INSTALLED after register-from-install succeeds.
 */
function install_finalize_after_registration($db, string $locationId, DateTimeImmutable $now): void
{
    $locationId = install_clean_location_id($locationId);
    if ($locationId === null) {
        return;
    }

    try {
        $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
    } catch (Exception $e) {
        error_log('[install_helpers] finalize_after_registration lookup failed for ' . $locationId . ': ' . $e->getMessage());

        return;
    }

    if (!$tokenSnap->exists()) {
        return;
    }

    $tokenData = $tokenSnap->data();
    $storedState = (string)($tokenData['install_state'] ?? '');
    if ($storedState === INSTALL_STATE_INSTALLED) {
        return;
    }
    if ($storedState !== INSTALL_STATE_PENDING_OAUTH) {
        return;
    }

    $resolutionMode = (string)($tokenData['install_resolution_mode'] ?? INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION);
    $checkpoint = install_final_install_checkpoint($locationId, $resolutionMode);
    if (empty($checkpoint['ok'])) {
        return;
    }

    $companyId = trim((string)($tokenData['companyId'] ?? $tokenData['company_id'] ?? ''));
    $classification = install_classify_location($db, $locationId, $companyId !== '' ? $companyId : null);
    $status = (string)($classification['status'] ?? INSTALL_STATE_LINKED_ACCOUNT);
    $decision = [
        'kind' => 'login',
        'status' => $status,
        'url' => null,
        'classification' => $classification,
    ];
    $resolutionSource = (string)($tokenData['install_resolution_source'] ?? 'register_from_install');
    if ($resolutionSource === '') {
        $resolutionSource = 'register_from_install';
    }

    install_finalize_location_install(
        $db,
        $locationId,
        $decision,
        $resolutionMode,
        $resolutionSource . '+registration_complete',
        $now
    );
}

/**
 * Fast registration finalization: one token read + one batch (no deep ownership scan).
 *
 * @param array{owner_user_id?:string,owner_email?:string,owner_name?:string,owner_phone?:string} $ownerContext
 */
function install_finalize_registered_location_fast(
    $db,
    string $locationId,
    array $ownerContext,
    DateTimeImmutable $now
): void {
    $locationId = install_clean_location_id($locationId);
    if ($locationId === null) {
        return;
    }

    try {
        $tokenRef = $db->collection('ghl_tokens')->document($locationId);
        $tokenSnap = $tokenRef->snapshot();
    } catch (Exception $e) {
        error_log('[install_helpers] finalize_registered_fast lookup failed for ' . $locationId . ': ' . $e->getMessage());

        return;
    }

    if (!$tokenSnap->exists()) {
        return;
    }

    $tokenData = $tokenSnap->data();
    $storedState = (string)($tokenData['install_state'] ?? '');
    if ($storedState === INSTALL_STATE_INSTALLED) {
        return;
    }
    if ($storedState !== INSTALL_STATE_PENDING_OAUTH) {
        return;
    }

    $hasLocationToken = trim((string)($tokenData['access_token'] ?? '')) !== ''
        && (($tokenData['userType'] ?? '') === 'Location'
            || install_clean_location_id($tokenData['location_id'] ?? ($tokenData['locationId'] ?? null)) === $locationId);
    if (!$hasLocationToken) {
        return;
    }

    $resolutionMode = (string)($tokenData['install_resolution_mode'] ?? INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION);
    if (empty(install_final_install_checkpoint($locationId, $resolutionMode)['ok'])) {
        return;
    }

    $resolutionSource = (string)($tokenData['install_resolution_source'] ?? 'register_from_install');
    if ($resolutionSource === '') {
        $resolutionSource = 'register_from_install';
    }
    $resolutionSource .= '+registration_complete';

    $installStatus = INSTALL_STATE_LINKED_ACCOUNT;
    $timestamp = new \Google\Cloud\Core\Timestamp($now);
    $classificationSummary = install_summarize_classification([
        'status' => $installStatus,
        'token_exists' => true,
        'linked' => true,
        'mismatch' => false,
    ]);

    $ownerUserId = trim((string)($ownerContext['owner_user_id'] ?? ''));
    $ownerFields = [];
    if ($ownerUserId !== '') {
        $ownerFields = [
            'location_id' => $locationId,
            'owner_user_id' => $ownerUserId,
            'owner_uid' => $ownerUserId,
            'owner_email' => install_norm_email($ownerContext['owner_email'] ?? ''),
            'owner_name' => trim((string)($ownerContext['owner_name'] ?? '')),
            'owner_phone' => preg_replace('/\s+/', '', trim((string)($ownerContext['owner_phone'] ?? ''))),
        ];
    }

    $tokenInstallData = array_merge($ownerFields, [
        'install_state' => INSTALL_STATE_INSTALLED,
        'install_status' => $installStatus,
        'is_live' => true,
        'toggle_enabled' => true,
        'install_resolution_mode' => $resolutionMode,
        'install_resolution_source' => $resolutionSource,
        'install_redirect_kind' => 'login',
        'install_classification' => $classificationSummary,
        'install_completed_at' => $timestamp,
        'uninstalled_at' => null,
        'uninstall_source' => null,
        'updated_at' => $timestamp,
    ]);

    // Set a default rate limit for new subaccounts if not already set
    if (!isset($tokenData['rate_limit'])) {
        $tokenInstallData['rate_limit'] = 10;
    }

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intData = [];
    try {
        $intSnap = $intRef->snapshot();
        $intData = $intSnap->exists() ? $intSnap->data() : [];
    } catch (Exception $e) {
        error_log('[install_helpers] finalize_registered_fast integration read failed for ' . $locationId . ': ' . $e->getMessage());
    }

    $integrationData = array_merge($ownerFields, [
        'location_id' => $locationId,
        'location_name' => (string)($tokenData['location_name'] ?? ''),
        'companyId' => $tokenData['companyId'] ?? ($tokenData['company_id'] ?? null),
        'company_name' => (string)($tokenData['company_name'] ?? ''),
        'access_token' => $tokenData['access_token'] ?? null,
        'refresh_token' => $tokenData['refresh_token'] ?? null,
        'scope' => $tokenData['scope'] ?? null,
        'expires_at' => $tokenData['expires_at'] ?? null,
        'client_id' => $tokenData['client_id'] ?? ($tokenData['appId'] ?? null),
        'app_type' => $tokenData['appType'] ?? 'subaccount',
        'install_state' => INSTALL_STATE_INSTALLED,
        'install_status' => $installStatus,
        'install_resolution_mode' => $resolutionMode,
        'install_resolution_source' => $resolutionSource,
        'install_redirect_kind' => 'login',
        'install_completed_at' => $timestamp,
        'uninstalled_at' => null,
        'uninstall_source' => null,
        'is_live' => true,
        'updated_at' => $timestamp,
    ]);

    if (!array_key_exists('free_credits_total', $intData)) {
        $integrationData['free_credits_total'] = 10;
    }
    if (!array_key_exists('free_usage_count', $intData)) {
        $integrationData['free_usage_count'] = 0;
    }
    if (!array_key_exists('credit_balance', $intData)) {
        $integrationData['credit_balance'] = 0;
    }
    if (!array_key_exists('system_default_sender', $intData)) {
        $integrationData['system_default_sender'] = 'NOLASMSPro';
    }
    if (!array_key_exists('installed_at', $intData)) {
        $integrationData['installed_at'] = $timestamp;
    }

    $batch = $db->batch();
    $batch->set($tokenRef, $tokenInstallData, ['merge' => true]);
    $batch->set($intRef, $integrationData, ['merge' => true]);
    $batch->commit();
}

function install_finalize_location_install(
    $db,
    string $locationId,
    array $decision,
    string $resolutionMode,
    string $resolutionSource,
    DateTimeImmutable $now,
    ?array $preloadedTokenData = null
): void {
    $checkpoint = install_final_install_checkpoint($locationId, $resolutionMode);
    if (empty($checkpoint['ok'])) {
        throw new RuntimeException((string)($checkpoint['message'] ?? 'OAuth not fully resolved'));
    }

    $classification = is_array($decision['classification'] ?? null) ? $decision['classification'] : [];
    $installStatus = (string)($decision['status'] ?? ($classification['status'] ?? ''));
    $redirectKind = (string)($decision['kind'] ?? '');
    $summary = install_summarize_classification($classification);
    $timestamp = new \Google\Cloud\Core\Timestamp($now);

    $tokenRef = $db->collection('ghl_tokens')->document($locationId);
    if ($preloadedTokenData !== null) {
        $tokenData = $preloadedTokenData;
    } else {
        $tokenSnap = $tokenRef->snapshot();
        $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
    }
    // Set a default rate limit for new subaccounts if not already set
    $existingRateLimit = isset($tokenData['rate_limit']) ? (int)$tokenData['rate_limit'] : null;

    $tokenInstallData = [
        'install_state' => INSTALL_STATE_INSTALLED,
        'install_status' => $installStatus,
        'is_live' => true,
        'toggle_enabled' => true,
        'rate_limit' => $existingRateLimit !== null ? $existingRateLimit : 10,
        'install_resolution_mode' => $resolutionMode,
        'install_resolution_source' => $resolutionSource,
        'install_redirect_kind' => $redirectKind,
        'install_classification' => $summary,
        'install_completed_at' => $timestamp,
        'provisioned_from_selection' => $resolutionSource === 'signed_install_selection'
            || strpos($resolutionSource, 'locations_unique_single') !== false
            || strpos($resolutionSource, 'locations_single') !== false
            || strpos($resolutionSource, 'approved_locations_unique_single') !== false
            || strpos($resolutionSource, 'approved_locations_single') !== false
            || strpos($resolutionSource, 'query_approved_locations_single') !== false,
        'uninstalled_at' => null,
        'uninstall_source' => null,
        'updated_at' => $timestamp,
    ];

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    $intRef = $db->collection('integrations')->document($intDocId);
    $intSnap = $intRef->snapshot();
    $intData = $intSnap->exists() ? $intSnap->data() : [];

    $integrationData = [
        'location_id' => $locationId,
        'location_name' => (string)($tokenData['location_name'] ?? ''),
        'companyId' => $tokenData['companyId'] ?? ($tokenData['company_id'] ?? null),
        'company_name' => (string)($tokenData['company_name'] ?? ''),
        'access_token' => $tokenData['access_token'] ?? null,
        'refresh_token' => $tokenData['refresh_token'] ?? null,
        'scope' => $tokenData['scope'] ?? null,
        'expires_at' => $tokenData['expires_at'] ?? null,
        'client_id' => $tokenData['client_id'] ?? ($tokenData['appId'] ?? null),
        'app_type' => $tokenData['appType'] ?? 'subaccount',
        'install_state' => INSTALL_STATE_INSTALLED,
        'install_status' => $installStatus,
        'install_resolution_mode' => $resolutionMode,
        'install_resolution_source' => $resolutionSource,
        'install_redirect_kind' => $redirectKind,
        'install_completed_at' => $timestamp,
        'uninstalled_at' => null,
        'uninstall_source' => null,
        'is_live' => true,
        'updated_at' => $timestamp,
    ];

    if (!$intSnap->exists() || !array_key_exists('free_credits_total', $intData)) {
        $integrationData['free_credits_total'] = 10;
    }
    if (!$intSnap->exists() || !array_key_exists('free_usage_count', $intData)) {
        $integrationData['free_usage_count'] = 0;
    }
    if (!$intSnap->exists() || !array_key_exists('credit_balance', $intData)) {
        $integrationData['credit_balance'] = 0;
    }
    if (!$intSnap->exists() || !array_key_exists('system_default_sender', $intData)) {
        $integrationData['system_default_sender'] = 'NOLASMSPro';
    }
    if (!$intSnap->exists() || !array_key_exists('installed_at', $intData)) {
        $integrationData['installed_at'] = $timestamp;
    }

    $batch = $db->batch();
    $batch->set($intRef, $integrationData, ['merge' => true]);
    $batch->set($tokenRef, $tokenInstallData, ['merge' => true]);
    $batch->commit();
}

function install_token_doc_exists($db, string $locationId): bool
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return false;
    }

    try {
        return $db->collection('ghl_tokens')->document($locationId)->snapshot()->exists();
    } catch (Exception $e) {
        error_log("[install_helpers] token exists check failed for {$locationId}: " . $e->getMessage());
        return false;
    }
}

function install_location_company_mismatch(
    $db,
    string $locationId,
    ?string $companyId,
    ?bool $tokenDocExists = null,
    ?array $tokenData = null
): bool {
    $companyId = trim((string)$companyId);
    if ($locationId === '') {
        return false;
    }

    try {
        if ($tokenDocExists !== null) {
            if (!$tokenDocExists) {
                return false;
            }
            $data = is_array($tokenData) ? $tokenData : [];
        } else {
            $snap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
            if (!$snap->exists()) {
                return false;
            }
            $data = $snap->data();
        }
        $storedLocation = install_clean_location_id($data['location_id'] ?? ($data['locationId'] ?? null));
        $storedCompany = trim((string)($data['companyId'] ?? $data['company_id'] ?? ''));
        $isAgencyLevelDoc = (($data['appType'] ?? '') === 'agency')
            && (($data['userType'] ?? '') !== 'Location')
            && ($storedLocation === null || $storedLocation === $storedCompany || $locationId === $storedCompany);
        if ($isAgencyLevelDoc) {
            return true;
        }
        if ($storedLocation !== null && $storedLocation !== $locationId) {
            return true;
        }
        if ($companyId === '') {
            return false;
        }
        return $storedCompany !== '' && $storedCompany !== $companyId;
    } catch (Exception $e) {
        error_log("[install_helpers] company mismatch check failed for {$locationId}: " . $e->getMessage());
        return false;
    }
}

function install_norm_email($value): string
{
    return strtolower(trim((string)$value));
}

function install_is_active_user(array $data): bool
{
    return !array_key_exists('active', $data) || !empty($data['active']);
}

function install_user_location_ids(array $data): array
{
    $ids = [];
    foreach ([
        'active_location_id',
        'location_id',
        'locationId',
        'ghl_location_id',
        'ghlLocationId',
        'selected_location_id',
        'selectedLocationId',
    ] as $key) {
        $clean = install_clean_location_id($data[$key] ?? null);
        if ($clean !== null) {
            $ids[] = $clean;
        }
    }

    $tokenRef = trim((string)($data['ghl_token_ref'] ?? ''));
    if ($tokenRef !== '' && preg_match('#^ghl_tokens/([^/]+)$#', $tokenRef, $m)) {
        $clean = install_clean_location_id($m[1]);
        if ($clean !== null) {
            $ids[] = $clean;
        }
    }

    return array_values(array_unique($ids));
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_user_doc($doc, string $locationId, string $source): ?array
{
    if (!$doc || !$doc->exists()) {
        return null;
    }

    $data = $doc->data();
    if (!is_array($data) || !install_is_active_user($data)) {
        return null;
    }

    $role = strtolower(trim((string)($data['role'] ?? 'user')));
    if ($role === 'agency') {
        return null;
    }

    if (!in_array($locationId, install_user_location_ids($data), true)) {
        return null;
    }

    $email = install_norm_email($data['email'] ?? '');
    if ($email === '') {
        return null;
    }

    return [
        'id' => $doc->id(),
        'email' => $email,
        'name' => trim((string)($data['name'] ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? '')))),
        'source' => $source,
    ];
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_owner_like_doc($doc, string $source): ?array
{
    if (!$doc || !$doc->exists()) {
        return null;
    }

    $data = $doc->data();
    if (!is_array($data)) {
        return null;
    }

    $email = install_norm_email(
        $data['owner_email']
            ?? $data['email']
            ?? $data['user_email']
            ?? $data['account_email']
            ?? ''
    );
    if ($email === '') {
        return null;
    }

    return [
        'id' => trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? '')),
        'email' => $email,
        'name' => trim((string)($data['owner_name'] ?? $data['name'] ?? $data['full_name'] ?? '')),
        'source' => $source,
    ];
}

/**
 * Ensure owner-like linked-account rows still map to a real, active user.
 * This prevents stale `location_owners` rows from forcing welcome-back routing
 * after the user document has been deleted for reinstall testing.
 *
 * @param array{id:string,email:string,name:string,source:string}|null $linked
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_validate_linked_account_user_exists($db, ?array $linked): ?array
{
    if ($linked === null) {
        return null;
    }

    $uid = trim((string)($linked['id'] ?? ''));
    $email = install_norm_email($linked['email'] ?? '');
    if ($uid === '' && $email === '') {
        return null;
    }

    if ($uid !== '') {
        try {
            $userSnap = $db->collection('users')->document($uid)->snapshot();
            if ($userSnap->exists()) {
                $data = $userSnap->data();
                if (is_array($data) && install_is_active_user($data)) {
                    $userEmail = install_norm_email($data['email'] ?? '');
                    if ($email === '' || $userEmail === '' || $userEmail === $email) {
                        $linked['email'] = $email !== '' ? $email : $userEmail;
                        return $linked;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[install_helpers] linked-account uid validation failed: ' . $e->getMessage());
        }
    }

    if ($email !== '') {
        try {
            foreach ($db->collection('users')->where('email', '=', $email)->limit(1)->documents() as $doc) {
                if (!$doc->exists()) {
                    continue;
                }
                $data = $doc->data();
                if (!is_array($data) || !install_is_active_user($data)) {
                    continue;
                }
                $linked['id'] = $uid !== '' ? $uid : $doc->id();
                return $linked;
            }
        } catch (Exception $e) {
            error_log('[install_helpers] linked-account email validation failed: ' . $e->getMessage());
        }
    }

    return null;
}

function install_backfill_location_owner($db, string $locationId, ?array $linkedAccount): void
{
    if ($locationId === '' || empty($linkedAccount['email'])) {
        return;
    }

    $now = new DateTimeImmutable();
    if (install_claim_owner_lock(
        $db,
        'location_owners',
        $locationId,
        (string)($linkedAccount['id'] ?? ''),
        install_norm_email($linkedAccount['email']),
        trim((string)($linkedAccount['name'] ?? '')),
        $now,
        'install_self_heal:' . (string)($linkedAccount['source'] ?? 'unknown')
    )) {
        return;
    }

    error_log("[install_helpers] owner backfill skipped for {$locationId}: canonical owner conflict");
}

function install_claim_owner_lock(
    $db,
    string $collection,
    string $entityId,
    string $ownerUserId,
    string $ownerEmail,
    string $ownerName,
    ?DateTimeImmutable $now = null,
    string $source = 'install_registration'
): bool {
    $entityId = trim($entityId);
    $ownerUserId = trim($ownerUserId);
    $ownerEmail = install_norm_email($ownerEmail);
    if ($entityId === '' || ($ownerUserId === '' && $ownerEmail === '')) {
        return false;
    }

    $now = $now ?: new DateTimeImmutable();
    $payload = [
        'entity_id' => $entityId,
        'owner_user_id' => $ownerUserId,
        'owner_email' => $ownerEmail,
        'owner_name' => $ownerName,
        'source' => $source,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];

    try {
        $createPayload = $payload + [
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
        ];
        $db->collection($collection)->document($entityId)->create($createPayload);
        return true;
    } catch (Exception $e) {
        // Existing lock is expected on reinstall/continuation. Validate before merging.
    }

    try {
        $ref = $db->collection($collection)->document($entityId);
        $snap = $ref->snapshot();
        if (!$snap->exists()) {
            $ref->set($payload + ['created_at' => new \Google\Cloud\Core\Timestamp($now)], ['merge' => true]);
            return true;
        }

        $data = $snap->data();
        $existingUid = trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? ''));
        $existingEmail = install_norm_email($data['owner_email'] ?? $data['email'] ?? $data['user_email'] ?? $data['account_email'] ?? '');

        if ($existingUid !== '' && $ownerUserId !== '' && $existingUid !== $ownerUserId) {
            return false;
        }
        if ($existingEmail !== '' && $ownerEmail !== '' && $existingEmail !== $ownerEmail) {
            return false;
        }

        $ref->set($payload, ['merge' => true]);
        return true;
    } catch (Exception $e) {
        error_log("[install_helpers] owner lock claim failed for {$collection}/{$entityId}: " . $e->getMessage());
    }

    return false;
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_for_location($db, string $locationId, bool $deepFallback = true): ?array
{
    $locationId = trim($locationId);
    if ($locationId === '') {
        return null;
    }

    try {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($ownerSnap, 'location_owners');
        if ($linked !== null) {
            $linked = install_validate_linked_account_user_exists($db, $linked);
            if ($linked === null) {
                error_log("[install_helpers] ignored stale location_owners entry for {$locationId} (owner user missing)");
            } else {
            return $linked;
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] owner lookup failed for {$locationId}: " . $e->getMessage());
    }

    if (!$deepFallback) {
        // Deep (collection-group) queries are disabled: skip the slow fallback
        // paths entirely. The canonical location_owners check above already ran.
        return null;
    }

    foreach ([
        'active_location_id',
        'location_id',
        'locationId',
        'ghl_location_id',
        'ghlLocationId',
        'selected_location_id',
        'selectedLocationId',
    ] as $field) {
        try {
            $userQuery = $db->collection('users')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($userQuery as $doc) {
                $linked = install_linked_account_from_user_doc($doc, $locationId, 'users.' . $field);
                if ($linked !== null) {
                    install_backfill_location_owner($db, $locationId, $linked);
                    return $linked;
                }
            }
        } catch (Exception $e) {
            error_log("[install_helpers] user {$field} lookup failed for {$locationId}: " . $e->getMessage());
        }
    }

    foreach ([
        'location_id',
        'locationId',
        'entity_id',
        'id',
    ] as $field) {
        try {
            $subQuery = $db->collectionGroup('subaccounts')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($subQuery as $subDoc) {
                $linked = install_linked_account_from_subaccount_doc($subDoc, $locationId, 'users.subaccounts.' . $field);
                if ($linked !== null) {
                    install_backfill_location_owner($db, $locationId, $linked);
                    return $linked;
                }
            }
        } catch (Exception $e) {
            error_log("[install_helpers] subaccount {$field} lookup failed for {$locationId}: " . $e->getMessage());
        }
    }

    try {
        $subQuery = $db->collectionGroup('subaccounts')
            ->where(\Google\Cloud\Firestore\FieldPath::documentId(), '=', $locationId)
            ->limit(1)
            ->documents();
        foreach ($subQuery as $subDoc) {
            $linked = install_linked_account_from_subaccount_doc($subDoc, $locationId, 'users.subaccounts.document_id');
            if ($linked !== null) {
                install_backfill_location_owner($db, $locationId, $linked);
                return $linked;
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] subaccount document-id lookup failed for {$locationId}: " . $e->getMessage());
    }

    $linked = install_linked_account_for_location_owner_fallbacks($db, $locationId);
    if ($linked !== null) {
        $linked = install_validate_linked_account_user_exists($db, $linked);
    }
    if ($linked !== null) {
        install_backfill_location_owner($db, $locationId, $linked);
    }

    return $linked;
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_from_subaccount_doc($subDoc, string $locationId, string $source): ?array
{
    if (!$subDoc || !$subDoc->exists()) {
        return null;
    }

    $subData = $subDoc->data();
    $subLoc = install_clean_location_id($subData['location_id'] ?? $subData['locationId'] ?? $subData['entity_id'] ?? $subData['id'] ?? null);
    if ($subLoc !== null && $subLoc !== $locationId) {
        return null;
    }

    $parentUserRef = $subDoc->reference()->parent()->parent();
    if ($parentUserRef === null) {
        return null;
    }

    try {
        $parentSnap = $parentUserRef->snapshot();
        if (!$parentSnap->exists()) {
            return null;
        }

        $data = $parentSnap->data();
        if (!is_array($data) || !install_is_active_user($data)) {
            return null;
        }

        $role = strtolower(trim((string)($data['role'] ?? 'user')));
        if ($role === 'agency') {
            return null;
        }

        $email = install_norm_email($data['email'] ?? '');
        if ($email === '') {
            return null;
        }

        return [
            'id' => $parentSnap->id(),
            'email' => $email,
            'name' => trim((string)($data['name'] ?? trim((string)($data['firstName'] ?? '') . ' ' . (string)($data['lastName'] ?? '')))),
            'source' => $source,
        ];
    } catch (Exception $e) {
        error_log("[install_helpers] subaccount parent lookup failed for {$locationId}: " . $e->getMessage());
        return null;
    }
}

/**
 * @return array{id:string,email:string,name:string,source:string}|null
 */
function install_linked_account_for_location_owner_fallbacks($db, string $locationId): ?array
{
    try {
        $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($tokenSnap, 'ghl_tokens.owner');
        if ($linked !== null) {
            return $linked;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] token owner fallback failed for {$locationId}: " . $e->getMessage());
    }

    try {
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        $linked = install_linked_account_from_owner_like_doc($intSnap, 'integrations.owner');
        if ($linked !== null) {
            return $linked;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] integration owner fallback failed for {$locationId}: " . $e->getMessage());
    }

    return null;
}

function install_user_linked_to_location($db, string $uid, string $locationId, ?string $email = null): bool
{
    $uid = trim($uid);
    $locationId = trim($locationId);
    if ($uid === '' || $locationId === '') {
        return false;
    }

    try {
        $memberSnap = $db->collection('location_owners')->document($locationId)->collection('members')->document($uid)->snapshot();
        if ($memberSnap->exists()) {
            return true;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] location member lookup failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        if ($ownerSnap->exists()) {
            $ownerData = $ownerSnap->data();
            $ownerUid = trim((string)($ownerData['owner_user_id'] ?? $ownerData['owner_uid'] ?? $ownerData['user_id'] ?? $ownerData['uid'] ?? ''));
            $ownerEmail = install_norm_email($ownerData['owner_email'] ?? $ownerData['email'] ?? $ownerData['user_email'] ?? $ownerData['account_email'] ?? '');

            if ($ownerUid !== '') {
                return $ownerUid === $uid;
            }

            if ($email !== null && $ownerEmail !== '') {
                return $ownerEmail === install_norm_email($email);
            }

            return false;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked owner check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        $userSnap = $db->collection('users')->document($uid)->snapshot();
        if (!$userSnap->exists()) {
            return false;
        }
        $userData = $userSnap->data();
        if (in_array($locationId, install_user_location_ids($userData), true)) {
            install_backfill_location_owner($db, $locationId, install_linked_account_from_user_doc($userSnap, $locationId, 'users.legacy_verify'));
            return true;
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked root check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        return $db->collection('users')->document($uid)->collection('subaccounts')->document($locationId)->snapshot()->exists();
    } catch (Exception $e) {
        error_log("[install_helpers] user linked subaccount check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    try {
        foreach (['location_id', 'locationId', 'entity_id', 'id'] as $field) {
            $subQuery = $db->collection('users')->document($uid)->collection('subaccounts')
                ->where($field, '=', $locationId)
                ->limit(1)
                ->documents();
            foreach ($subQuery as $subDoc) {
                if ($subDoc->exists()) {
                    return true;
                }
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] user linked subaccount field check failed for {$uid}/{$locationId}: " . $e->getMessage());
    }

    return false;
}

/**
 * Fast routing classification right after Marketplace selection (avoids slow legacy scans).
 *
 * @return array{status:string,token_exists:bool,linked:bool,linked_account:?array,mismatch:bool,install_state?:string}
 */
function install_classify_location_for_provision(
    $db,
    string $locationId,
    ?string $companyId,
    bool $tokenExistedBefore,
    ?bool $preloadedTokenExists = null,
    ?array $preloadedTokenData = null
): array {
    $locationId = trim($locationId);
    $mismatch = install_location_company_mismatch(
        $db,
        $locationId,
        $companyId,
        $preloadedTokenExists,
        $preloadedTokenData
    );
    if ($mismatch) {
        return [
            'status' => INSTALL_STATE_COMPANY_MISMATCH,
            'token_exists' => $tokenExistedBefore,
            'linked' => false,
            'linked_account' => null,
            'mismatch' => true,
            'install_state' => INSTALL_STATE_PENDING_OAUTH,
        ];
    }

    // Brand-new selection: no prior location token and no stored doc — skip owner scan.
    if (!$tokenExistedBefore && $preloadedTokenExists === false) {
        return [
            'status' => INSTALL_STATE_FRESH_INSTALL,
            'token_exists' => false,
            'linked' => false,
            'linked_account' => null,
            'mismatch' => false,
            'install_state' => INSTALL_STATE_PENDING_OAUTH,
        ];
    }

    $linkedAccount = null;
    try {
        $ownerSnap = $db->collection('location_owners')->document($locationId)->snapshot();
        $linkedAccount = install_linked_account_from_owner_like_doc($ownerSnap, 'location_owners');
        if ($linkedAccount !== null) {
            $linkedAccount = install_validate_linked_account_user_exists($db, $linkedAccount);
        }
    } catch (Exception $e) {
        error_log("[install_helpers] provision owner lookup failed for {$locationId}: " . $e->getMessage());
    }

    if ($linkedAccount !== null) {
        return [
            'status' => INSTALL_STATE_LINKED_ACCOUNT,
            'token_exists' => true,
            'linked' => true,
            'linked_account' => $linkedAccount,
            'mismatch' => false,
            'install_state' => INSTALL_STATE_PENDING_OAUTH,
        ];
    }

    if ($tokenExistedBefore) {
        return [
            'status' => INSTALL_STATE_TOKEN_ONLY,
            'token_exists' => true,
            'linked' => false,
            'linked_account' => null,
            'mismatch' => false,
            'install_state' => INSTALL_STATE_PENDING_OAUTH,
        ];
    }

    return [
        'status' => INSTALL_STATE_FRESH_INSTALL,
        'token_exists' => false,
        'linked' => false,
        'linked_account' => null,
        'mismatch' => false,
        'install_state' => INSTALL_STATE_PENDING_OAUTH,
    ];
}

/**
 * @return array{status:string,token_exists:bool,linked:bool,linked_account:?array,mismatch:bool}
 */
function install_classify_location(
    $db,
    string $locationId,
    ?string $companyId = null,
    ?bool $tokenExistedBefore = null,
    bool $deepOwnershipFallback = true
): array {
    $locationId = trim($locationId);
    $tokenData = [];
    $storedInstallState = '';
    $tokenSnapExists = false;
    try {
        $tokenSnapForState = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        if ($tokenSnapForState->exists()) {
            $tokenSnapExists = true;
            $tokenData = $tokenSnapForState->data();
            $storedInstallState = (string)($tokenData['install_state'] ?? '');
        }
    } catch (Exception $e) {
        error_log("[install_helpers] token state lookup failed for {$locationId}: " . $e->getMessage());
    }

    $tokenExists = $tokenExistedBefore ?? $tokenSnapExists;
    $pendingOAuth = $tokenExistedBefore === null && $storedInstallState === INSTALL_STATE_PENDING_OAUTH;
    if ($pendingOAuth) {
        // PENDING_OAUTH after location token exchange means "finish registration", not
        // "OAuth still resolving". Only block when the location token is not provisioned yet.
        $hasProvisionedLocationToken = trim((string)($tokenData['access_token'] ?? '')) !== ''
            && (($tokenData['userType'] ?? '') === 'Location'
                || install_clean_location_id($tokenData['location_id'] ?? ($tokenData['locationId'] ?? null)) === $locationId);
        if (!$hasProvisionedLocationToken) {
            return [
                'status' => INSTALL_STATE_INSTALL_PENDING,
                'token_exists' => $tokenExists,
                'linked' => false,
                'linked_account' => null,
                'mismatch' => false,
                'install_state' => $storedInstallState,
            ];
        }
        $tokenExists = true;
    }

    $mismatch = install_location_company_mismatch($db, $locationId, $companyId);
    $linkedAccount = install_linked_account_for_location($db, $locationId, $deepOwnershipFallback);
    $linked = $linkedAccount !== null;
    if ($linked) {
        install_backfill_location_owner($db, $locationId, $linkedAccount);
    }

    if ($mismatch) {
        $status = INSTALL_STATE_COMPANY_MISMATCH;
    } elseif ($linked) {
        $status = INSTALL_STATE_LINKED_ACCOUNT;
    } elseif ($tokenExists) {
        $status = INSTALL_STATE_TOKEN_ONLY;
    } else {
        $status = INSTALL_STATE_FRESH_INSTALL;
    }

    return [
        'status' => $status,
        'token_exists' => $tokenExists,
        'linked' => $linked,
        'linked_account' => $linkedAccount,
        'mismatch' => $mismatch,
        'install_state' => $storedInstallState,
    ];
}

function install_registration_status_for_account($db, string $locationId): string
{
    $classification = install_classify_location($db, $locationId);
    if (($classification['status'] ?? '') === INSTALL_STATE_LINKED_ACCOUNT) {
        return 'registered';
    }
    if (($classification['status'] ?? '') === INSTALL_STATE_TOKEN_ONLY) {
        return 'unregistered';
    }

    return 'not_installed';
}

function install_build_registration_url(
    string $jwtSecret,
    string $locationId,
    string $locationName,
    ?string $companyId,
    string $companyName,
    string $resolutionSource,
    string $installStatus = '',
    array $extraClaims = []
): string {
    $payload = [
        'type' => 'install',
        'location_id' => $locationId,
        'location_name' => $locationName,
        'company_id' => $companyId,
        'company_name' => $companyName,
        'resolution_source' => $resolutionSource,
    ];
    if ($installStatus !== '') {
        $payload['install_status'] = $installStatus;
    }
    foreach ($extraClaims as $k => $v) {
        $payload[$k] = $v;
    }

    return 'https://smspro-api.nolacrm.io/register?install_token=' . urlencode(jwt_sign($payload, $jwtSecret, 900));
}

/**
 * Record a non-primary user linked to a sub-account under location_owners/{locationId}/members/{userId}.
 *
 * @param array<string,mixed> $extra
 */
function install_record_location_member(
    $db,
    string $locationId,
    string $userId,
    string $email,
    string $fullName,
    string $phone,
    DateTimeImmutable $now,
    string $source,
    array $extra = []
): void {
    $locationId = trim($locationId);
    $userId = trim($userId);
    if ($locationId === '' || $userId === '') {
        return;
    }

    $row = array_merge([
        'entity_id' => $locationId,
        'user_id' => $userId,
        'owner_user_id' => $userId,
        'email' => install_norm_email($email),
        'owner_email' => install_norm_email($email),
        'name' => trim($fullName),
        'owner_name' => trim($fullName),
        'phone' => trim($phone),
        'owner_phone' => trim($phone),
        'is_additional_location_member' => true,
        'source' => $source,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], $extra);

    try {
        $ref = $db->collection('location_owners')->document($locationId)->collection('members')->document($userId);
        $snap = $ref->snapshot();
        if (!$snap->exists()) {
            $row['created_at'] = new \Google\Cloud\Core\Timestamp($now);
        }
        $ref->set($row, ['merge' => true]);
    } catch (Exception $e) {
        error_log("[install_helpers] install_record_location_member failed for {$locationId}/members/{$userId}: " . $e->getMessage());
    }
}

/**
 * Claim canonical location_owners/{locationId} when empty, or attach as members/{userId} when another owner exists.
 *
 * @return 'primary'|'member'
 */
function install_attach_user_to_location_ownership(
    $db,
    string $locationId,
    string $userId,
    string $email,
    string $fullName,
    string $phone,
    DateTimeImmutable $now,
    string $source
): string {
    $locationId = trim($locationId);
    $userId = trim($userId);
    $email = install_norm_email($email);
    if ($locationId === '' || $userId === '' || $email === '') {
        return 'member';
    }

    try {
        $ref = $db->collection('location_owners')->document($locationId);
        $snap = $ref->snapshot();
        if (!$snap->exists()) {
            if (install_claim_owner_lock($db, 'location_owners', $locationId, $userId, $email, $fullName, $now, $source)) {
                return 'primary';
            }

            install_record_location_member($db, $locationId, $userId, $email, $fullName, $phone, $now, $source . ':claim_race');
            return 'member';
        }

        $data = $snap->data();
        $existingUid = trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? $data['user_id'] ?? $data['uid'] ?? ''));
        $existingEmail = install_norm_email($data['owner_email'] ?? $data['email'] ?? $data['user_email'] ?? $data['account_email'] ?? '');

        if ($existingUid !== '' && $existingUid === $userId) {
            install_claim_owner_lock($db, 'location_owners', $locationId, $userId, $email, $fullName, $now, $source);
            return 'primary';
        }

        if ($existingUid === '' && $existingEmail === '') {
            if (install_claim_owner_lock($db, 'location_owners', $locationId, $userId, $email, $fullName, $now, $source)) {
                return 'primary';
            }
        }

        if ($existingUid !== '' && $existingUid !== $userId) {
            install_record_location_member($db, $locationId, $userId, $email, $fullName, $phone, $now, $source);
            return 'member';
        }

        if ($existingEmail !== '' && $existingEmail !== $email) {
            install_record_location_member($db, $locationId, $userId, $email, $fullName, $phone, $now, $source);
            return 'member';
        }

        if (install_claim_owner_lock($db, 'location_owners', $locationId, $userId, $email, $fullName, $now, $source)) {
            return 'primary';
        }

        install_record_location_member($db, $locationId, $userId, $email, $fullName, $phone, $now, $source . ':fallback');
        return 'member';
    } catch (Exception $e) {
        error_log("[install_helpers] install_attach_user_to_location_ownership failed for {$locationId}/{$userId}: " . $e->getMessage());
        return 'member';
    }
}

/**
 * Provision one selected sub-account from a company token and return the redirect URL.
 *
 * @param array<string,mixed> $companyData
 * @return array{ok:bool,url:?string,error:?string,decision?:array<string,mixed>}
 */
function install_complete_company_location_selection(
    $db,
    string $jwtSecret,
    string $companyId,
    string $companyName,
    array $companyData,
    string $locationId,
    string $locationName,
    string $resolutionSource,
    ?string $selectionSessionId = null,
    bool $skipClaimRecord = false
): array {
    $locationId = install_clean_location_id($locationId);
    $companyId = trim($companyId);
    if ($locationId === null || $companyId === '') {
        return ['ok' => false, 'url' => null, 'error' => 'Missing company or location context.'];
    }

    $companyToken = (string)($companyData['access_token'] ?? '');
    if ($companyToken === '') {
        return ['ok' => false, 'url' => null, 'error' => 'Company token is empty.'];
    }

    $preloadedTokenExists = false;
    $preloadedTokenData = [];
    try {
        $preloadedTokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
        $preloadedTokenExists = $preloadedTokenSnap->exists();
        if ($preloadedTokenExists) {
            $preloadedTokenData = $preloadedTokenSnap->data();
        }
    } catch (Exception $e) {
        error_log("[install_helpers] preloaded token read failed for {$locationId}: " . $e->getMessage());
    }

    if (install_location_company_mismatch(
        $db,
        $locationId,
        $companyId,
        $preloadedTokenExists,
        $preloadedTokenData
    )) {
        return ['ok' => false, 'url' => null, 'error' => 'Selected subaccount belongs to a different GoHighLevel company.'];
    }

    if (!$skipClaimRecord && $selectionSessionId !== null && $selectionSessionId !== '') {
        install_record_selection_claim($db, $selectionSessionId, $companyId, $locationId);
    }

    $tokenExistedBefore = $preloadedTokenExists;
    $exchange = install_exchange_location_token($companyToken, $companyId, $locationId);
    if (!$exchange['ok']) {
        return [
            'ok' => false,
            'url' => null,
            'error' => 'Failed to exchange selected subaccount token.',
        ];
    }

    $ltData = $exchange['data'];
    $now = new DateTimeImmutable();
    $expiresAt = time() + (int)($ltData['expires_in'] ?? 86400);
    $locationName = trim($locationName);
    if ($locationName === '') {
        // Selection UI already lists workspace names; avoid a blocking GHL locations GET.
        if (strpos($resolutionSource, 'selection') !== false) {
            $locationName = 'Workspace';
        } else {
            $locationName = install_fetch_location_name_with_token(
                (string)$ltData['access_token'],
                $locationId,
                ''
            );
        }
    }

    $clientId = $companyData['client_id'] ?? $companyData['appId'] ?? null;
    $freshTokenData = array_merge($preloadedTokenData, [
        'access_token' => $ltData['access_token'],
        'refresh_token' => $ltData['refresh_token'] ?? ($companyData['refresh_token'] ?? null),
        'expires_at' => $expiresAt,
        'client_id' => $clientId,
        'appId' => $clientId,
        'appType' => 'subaccount',
        'userType' => 'Location',
        'location_id' => $locationId,
        'location_name' => $locationName,
        'companyId' => $companyId,
        'company_name' => $companyName,
        'install_state' => INSTALL_STATE_PENDING_OAUTH,
        'install_status' => INSTALL_STATE_INSTALL_PENDING,
        'install_resolution_mode' => INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        'install_resolution_source' => $resolutionSource,
        'provisioned_from_selection' => strpos($resolutionSource, 'selection') !== false,
        'selection_session_id' => $selectionSessionId,
        'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ]);
    $db->collection('ghl_tokens')->document($locationId)->set($freshTokenData, ['merge' => true]);

    $decision = install_decide_location_redirect(
        $db,
        $jwtSecret,
        $locationId,
        $locationName,
        $companyId,
        $companyName,
        $resolutionSource,
        $tokenExistedBefore,
        false,
        true,
        $preloadedTokenExists,
        $preloadedTokenData
    );

    if (($decision['kind'] ?? '') === 'error' || empty($decision['url'])) {
        return [
            'ok' => false,
            'url' => null,
            'error' => 'Selected subaccount could not be finalized.',
            'decision' => $decision,
        ];
    }

    install_maybe_finalize_location_install(
        $db,
        $locationId,
        $decision,
        INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        $resolutionSource,
        $now,
        $freshTokenData
    );

    if ($selectionSessionId !== null && $selectionSessionId !== '') {
        $sessionIdForUpdate = $selectionSessionId;
        $sessionUpdatePayload = [
            'status' => 'selected',
            'selected_location_id' => $locationId,
            'selected_location_name' => $locationName,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ];
        register_shutdown_function(static function () use ($db, $sessionIdForUpdate, $sessionUpdatePayload): void {
            try {
                $db->collection('install_sessions')->document($sessionIdForUpdate)->set($sessionUpdatePayload, ['merge' => true]);
            } catch (Exception $e) {
                error_log('[install_helpers] selection session update failed: ' . $e->getMessage());
            }
        });
    }

    return [
        'ok' => true,
        'url' => (string)$decision['url'],
        'error' => null,
        'decision' => $decision,
    ];
}

/**
 * Idempotent claim for install selection (avoids slow create+re-read on retries).
 */
function install_record_selection_claim($db, string $sessionId, string $companyId, string $locationId): void
{
    $sessionId = trim($sessionId);
    $locationId = install_clean_location_id($locationId);
    if ($sessionId === '' || $locationId === null) {
        return;
    }

    $claimRef = $db->collection('install_selection_claims')->document($sessionId);
    try {
        $claimSnap = $claimRef->snapshot();
        if ($claimSnap->exists()) {
            $claimedLocationId = install_clean_location_id($claimSnap->data()['selected_location_id'] ?? null);
            if ($claimedLocationId !== null && $claimedLocationId !== $locationId) {
                throw new RuntimeException('Install session already claimed for a different subaccount.');
            }
            return;
        }
    } catch (RuntimeException $e) {
        throw $e;
    } catch (Exception $e) {
        error_log('[install_helpers] selection claim read failed: ' . $e->getMessage());
    }

    try {
        $claimRef->set([
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'selected_location_id' => $locationId,
            'created_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ], ['merge' => true]);
    } catch (Exception $e) {
        error_log('[install_helpers] selection claim write failed: ' . $e->getMessage());
    }
}

/**
 * @param array<int,array{location_id:string,location_name?:string}> $candidateLocations
 */
function install_try_server_redirect_single_selection(
    $db,
    string $jwtSecret,
    string $companyId,
    string $companyName,
    array $companyData,
    array $candidateLocations,
    string $resolutionSource,
    ?string $selectionSessionId = null
): bool {
    $candidateIds = [];
    $candidateNames = [];
    foreach ($candidateLocations as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = install_clean_location_id($row['location_id'] ?? $row['locationId'] ?? $row['id'] ?? null);
        if ($id === null) {
            continue;
        }
        $candidateIds[] = $id;
        $name = trim((string)($row['location_name'] ?? $row['locationName'] ?? $row['name'] ?? ''));
        if ($name !== '') {
            $candidateNames[$id] = $name;
        }
    }
    $candidateIds = install_unique_ids($candidateIds);
    if (count($candidateIds) !== 1) {
        return false;
    }

    $locationId = $candidateIds[0];
    $result = install_complete_company_location_selection(
        $db,
        $jwtSecret,
        $companyId,
        $companyName,
        $companyData,
        $locationId,
        (string)($candidateNames[$locationId] ?? ''),
        $resolutionSource,
        $selectionSessionId
    );

    if (!$result['ok'] || empty($result['url'])) {
        error_log('[install_helpers] server_redirect_single_selection_failed locationId=' . $locationId . ' error=' . ($result['error'] ?? 'unknown'));

        return false;
    }

    header('Location: ' . $result['url'], true, 302);
    error_log('[install_helpers] server_redirect_single_selection locationId=' . $locationId);
    exit;
}

/**
 * Exchange a company-scoped token into a location-scoped token.
 *
 * @return array{ok:bool, code:int, data:array, raw:string, format:string, failures:array}
 */
function install_exchange_location_token(
    string $companyToken,
    string $companyId,
    string $locationId,
    int $timeoutSeconds = 5
): array {
    $timeoutSeconds = max(3, min(10, $timeoutSeconds));
    $attempts = [
        [
            'format' => 'form',
            'body' => http_build_query(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
        [
            'format' => 'json',
            'body' => json_encode(['companyId' => $companyId, 'locationId' => $locationId]),
            'headers' => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/json',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ],
    ];

    $failures = [];
    foreach ($attempts as $attempt) {
        $ltCurl = curl_init($attempt['url'] ?? 'https://services.leadconnectorhq.com/oauth/locationToken');
        curl_setopt_array($ltCurl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $attempt['body'],
            CURLOPT_HTTPHEADER => $attempt['headers'],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        $raw = curl_exec($ltCurl);
        $code = curl_getinfo($ltCurl, CURLINFO_HTTP_CODE);
        $curlErrno = curl_errno($ltCurl);
        curl_close($ltCurl);
        $data = json_decode($raw ?: '', true);
        $jsonDecodeOk = is_array($data);
        if (!$jsonDecodeOk) {
            $data = [];
        }

        if (($code === 200 || $code === 201) && !empty($data['access_token'])) {
            return [
                'ok' => true,
                'code' => $code,
                'data' => $data,
                'raw' => (string)$raw,
                'format' => $attempt['format'],
                'failures' => $failures,
            ];
        }

        if (empty($data['access_token']) && is_string($raw) && $raw !== '') {
            if (preg_match('/"access_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['access_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"refresh_token"\s*:\s*"([^"]+)"/', $raw, $m)) {
                $data['refresh_token'] = stripcslashes($m[1]);
            }
            if (preg_match('/"expires_in"\s*:\s*(\d+)/', $raw, $m)) {
                $data['expires_in'] = (int)$m[1];
            }
        }

        if (($code === 200 || $code === 201) && !empty($data['access_token'])) {
            return [
                'ok' => true,
                'code' => $code,
                'data' => $data,
                'raw' => (string)$raw,
                'format' => $attempt['format'],
                'failures' => $failures,
            ];
        }

        $rawText = is_string($raw) ? $raw : '';
        $sanitizedRaw = preg_replace('/"(access_token|refresh_token)"\s*:\s*"[^"]*"/i', '"$1":"[REDACTED]"', $rawText);
        $failures[] = [
            'format' => $attempt['format'],
            'code' => $code,
            'json_decode_ok' => $jsonDecodeOk,
            'has_access_token_field' => !empty($data['access_token']) || (is_string($raw) && strpos($raw, '"access_token"') !== false),
            'raw' => substr($sanitizedRaw, 0, 400),
        ];

        if (in_array($code, [400, 401, 403, 404], true)) {
            $failures[count($failures) - 1]['reason'] = 'client_error_no_retry';
            break;
        }

        // Timeouts / transport errors: alternate Content-Type is unlikely to help.
        if ($curlErrno !== 0 || $code === 0) {
            $failures[count($failures) - 1]['reason'] = 'transport_error_no_retry';
            break;
        }
    }

    return [
        'ok' => false,
        'code' => 0,
        'data' => [],
        'raw' => '',
        'format' => 'none',
        'failures' => $failures,
    ];
}

function install_fetch_location_name_with_token(string $accessToken, string $locationId, string $fallback = ''): string
{
    if ($accessToken === '' || $locationId === '') {
        return $fallback;
    }
    if (trim($fallback) !== '') {
        return $fallback;
    }

    try {
        $ch = curl_init('https://services.leadconnectorhq.com/locations/' . urlencode($locationId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 200 && $code < 300) {
            $body = json_decode((string)$resp, true);
            $name = trim((string)($body['location']['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }
    } catch (Exception $e) {
        error_log("[install_helpers] location name fetch failed for {$locationId}: " . $e->getMessage());
    }

    return $fallback;
}

/**
 * @return array{kind:string,status:string,url:?string,classification:array}
 */
function install_decide_location_redirect(
    $db,
    string $jwtSecret,
    string $locationId,
    string $locationName,
    ?string $companyId,
    string $companyName,
    string $resolutionSource,
    ?bool $tokenExistedBefore = null,
    bool $deepOwnershipFallback = true,
    bool $provisionFast = false,
    ?bool $preloadedTokenExists = null,
    ?array $preloadedTokenData = null
): array {
    $classification = $provisionFast
        ? install_classify_location_for_provision(
            $db,
            $locationId,
            $companyId,
            (bool)$tokenExistedBefore,
            $preloadedTokenExists,
            $preloadedTokenData
        )
        : install_classify_location($db, $locationId, $companyId, $tokenExistedBefore, $deepOwnershipFallback);

    if (($classification['status'] ?? '') === INSTALL_STATE_COMPANY_MISMATCH) {
        return [
            'kind' => 'error',
            'status' => INSTALL_STATE_COMPANY_MISMATCH,
            'url' => null,
            'classification' => $classification,
        ];
    }

    if (($classification['status'] ?? '') === INSTALL_STATE_INSTALL_PENDING) {
        return [
            'kind' => 'pending',
            'status' => INSTALL_STATE_INSTALL_PENDING,
            'url' => null,
            'classification' => $classification,
        ];
    }

    if (!empty($classification['linked'])) {
        $url = 'https://smspro-api.nolacrm.io/login?welcome_back=1&name=' . urlencode($locationName ?: 'Your Sub-Account')
            . '&location_id=' . urlencode($locationId)
            . '&install_status=' . urlencode(INSTALL_STATE_LINKED_ACCOUNT)
            . '&resolution_source=' . urlencode($resolutionSource);
        if ($companyName !== '') {
            $url .= '&company=' . urlencode($companyName);
        }

        return [
            'kind' => 'login',
            'status' => INSTALL_STATE_LINKED_ACCOUNT,
            'url' => $url,
            'classification' => $classification,
        ];
    }

    $status = (string)($classification['status'] ?? INSTALL_STATE_FRESH_INSTALL);

    return [
        'kind' => 'register',
        'status' => $status,
        'url' => install_build_registration_url(
            $jwtSecret,
            $locationId,
            $locationName,
            $companyId,
            $companyName,
            $resolutionSource,
            $status
        ),
        'classification' => $classification,
    ];
}

/**
 * Delete a marketplace installation pick from Firestore once it has been consumed.
 */
function install_clear_marketplace_install_pick($db, string $companyId): void
{
    $companyId = install_clean_location_id($companyId);
    if ($companyId === null) {
        return;
    }
    try {
        $db->collection('marketplace_install_picks')->document($companyId)->delete();
        error_log("[install_helpers] Cleared marketplace_install_picks for company={$companyId}");
    } catch (Exception $e) {
        error_log('[install_helpers] Failed to delete marketplace_install_pick: ' . $e->getMessage());
    }
}
