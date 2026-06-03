<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../webhook/firestore_client.php';

validate_api_request();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

ignore_user_abort(true);
if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}
@ini_set('max_execution_time', '0');

function provision_maybe_update_session($sessionRef, int $processed, int $total, int $provisioned, int $failed, array $errors, array $provisionedLocations = []): void
{
    if ($processed !== 1 && $processed % 10 !== 0 && $processed !== $total) {
        return;
    }

    try {
        $update = [
            'status' => 'provisioning',
            'progress' => [
                'total_locations' => $total,
                'provisioned' => $provisioned,
                'failed' => $failed,
            ],
            'errors' => array_slice($errors, 0, 25),
            'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ];
        if (!empty($provisionedLocations)) {
            $update['provisioned_locations'] = array_slice($provisionedLocations, 0, 100);
            $update['first_location'] = $provisionedLocations[0];
            $update['single_location'] = count($provisionedLocations) === 1 ? $provisionedLocations[0] : null;
        }
        $sessionRef->set($update, ['merge' => true]);
    } catch (Exception $e) {
        error_log('[install_provision] progress update failed: ' . $e->getMessage());
    }
}

function provision_fetch_locations(string $companyId, string $companyToken): array
{
    $all = [];
    $skip = 0;
    $limit = 100;
    do {
        $url = "https://services.leadconnectorhq.com/locations/search?companyId={$companyId}&skip={$skip}&limit={$limit}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $companyToken,
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code < 200 || $code >= 300) {
            break;
        }
        $body = json_decode((string)$resp, true);
        $fetched = $body['locations'] ?? [];
        foreach ($fetched as $loc) {
            $locId = (string)($loc['id'] ?? '');
            if ($locId !== '') {
                $all[$locId] = (string)($loc['name'] ?? '');
            }
        }
        $skip += $limit;
    } while (!empty($fetched) && count($fetched) === $limit && count($all) < 1000);

    return $all;
}

$sessionId = trim((string)($_GET['session_id'] ?? $_POST['session_id'] ?? ''));
if ($sessionId === '') {
    http_response_code(422);
    echo json_encode(['error' => 'session_id is required']);
    exit;
}

try {
    $db = get_firestore();
    $sessionRef = $db->collection('install_sessions')->document($sessionId);
    $sessionSnap = $sessionRef->snapshot();
    if (!$sessionSnap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Install session not found']);
        exit;
    }

    $session = $sessionSnap->data();
    $companyId = (string)($session['company_id'] ?? '');
    if ($companyId === '') {
        http_response_code(422);
        echo json_encode(['error' => 'Session missing company_id']);
        exit;
    }

    $now = new DateTimeImmutable();
    $sessionRef->set([
        'status' => 'provisioning',
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);

    $companySnap = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if (!$companySnap->exists()) {
        $sessionRef->set([
            'status' => 'failed',
            'errors' => ['Missing company token document.'],
            'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ], ['merge' => true]);
        http_response_code(422);
        echo json_encode(['error' => 'Company token not found']);
        exit;
    }

    $companyData = $companySnap->data();
    $companyToken = (string)($companyData['access_token'] ?? '');
    $companyRefresh = $companyData['refresh_token'] ?? null;
    if ($companyToken === '') {
        $sessionRef->set([
            'status' => 'failed',
            'errors' => ['Company token is empty.'],
            'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ], ['merge' => true]);
        http_response_code(422);
        echo json_encode(['error' => 'Company access token missing']);
        exit;
    }

    $locations = provision_fetch_locations($companyId, $companyToken);
    $totalLocations = count($locations);
    $provisioned = 0;
    $failed = 0;
    $errors = [];
    $processed = 0;
    $provisionedLocations = [];
    $sessionRef->set([
        'progress' => [
            'total_locations' => $totalLocations,
            'provisioned' => 0,
            'failed' => 0,
        ],
        'provisioned_locations' => [],
        'first_location' => null,
        'single_location' => null,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ], ['merge' => true]);

    foreach ($locations as $locId => $locNameHint) {
        try {
            $ltCh = curl_init('https://services.leadconnectorhq.com/oauth/locationToken');
            curl_setopt_array($ltCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['companyId' => $companyId, 'locationId' => $locId]),
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $companyToken,
                    'Content-Type: application/x-www-form-urlencoded',
                    'Accept: application/json',
                    'Version: 2021-07-28',
                ],
            ]);
            $ltRespRaw = curl_exec($ltCh);
            $ltCode = curl_getinfo($ltCh, CURLINFO_HTTP_CODE);
            curl_close($ltCh);
            $ltBody = json_decode((string)$ltRespRaw, true) ?? [];

            $ltOk = $ltCode >= 200 && $ltCode < 300 && !empty($ltBody['access_token']);
            if (!$ltOk) {
                $failed++;
                $errors[] = "locationToken failed for {$locId} (HTTP {$ltCode})";
                $processed++;
                provision_maybe_update_session($sessionRef, $processed, $totalLocations, $provisioned, $failed, $errors, $provisionedLocations);
                continue;
            }

            $ltToken = (string)$ltBody['access_token'];
            $ltExpiresAt = time() + (int)($ltBody['expires_in'] ?? 86400);

            $locName = $locNameHint;
            $locFetch = curl_init("https://services.leadconnectorhq.com/locations/{$locId}");
            curl_setopt_array($locFetch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Bearer ' . $ltToken,
                    'Accept: application/json',
                    'Version: 2021-07-28',
                ],
            ]);
            $locRaw = curl_exec($locFetch);
            $locCode = curl_getinfo($locFetch, CURLINFO_HTTP_CODE);
            curl_close($locFetch);
            if ($locCode >= 200 && $locCode < 300) {
                $locBody = json_decode((string)$locRaw, true) ?? [];
                $locName = (string)($locBody['location']['name'] ?? $locName);
            }

            $ts = new DateTimeImmutable();
            $db->collection('ghl_tokens')->document((string)$locId)->set([
                'access_token' => $ltToken,
                'refresh_token' => $companyRefresh,
                'expires_at' => $ltExpiresAt,
                'client_id' => $companyData['client_id'] ?? $companyData['appId'] ?? null,
                'appId' => $companyData['appId'] ?? $companyData['client_id'] ?? null,
                'appType' => $companyData['appType'] ?? 'agency',
                'userType' => 'Location',
                'location_id' => (string)$locId,
                'location_name' => $locName,
                'companyId' => $companyId,
                'is_live' => true,
                'toggle_enabled' => true,
                'provisioned_from_bulk' => true,
                'updated_at' => new \Google\Cloud\Core\Timestamp($ts),
            ], ['merge' => true]);

            // Set default rate_limit only if not already configured
            $existingTokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
            if ($existingTokenSnap->exists() && !array_key_exists('rate_limit', $existingTokenSnap->data())) {
                $db->collection('ghl_tokens')->document((string)$locId)->set(['rate_limit' => 10], ['merge' => true]);
            }


            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
            $intRef = $db->collection('integrations')->document($intDocId);
            $intSnap = $intRef->snapshot();
            if (!$intSnap->exists()) {
                $intRef->set([
                    'location_id' => (string)$locId,
                    'location_name' => $locName,
                    'companyId' => $companyId,
                    'free_credits_total' => 10,
                    'free_usage_count' => 0,
                    'credit_balance' => 0,
                    'system_default_sender' => 'NOLASMSPro',
                    'installed_at' => new \Google\Cloud\Core\Timestamp($ts),
                    'updated_at' => new \Google\Cloud\Core\Timestamp($ts),
                ]);
            } else {
                $intRef->set([
                    'access_token' => $ltToken,
                    'expires_at' => $ltExpiresAt,
                    'location_name' => $locName,
                    'updated_at' => new \Google\Cloud\Core\Timestamp($ts),
                ], ['merge' => true]);
            }
            $provisioned++;
            $provisionedLocations[] = [
                'location_id' => (string)$locId,
                'location_name' => $locName,
            ];
            $processed++;
            provision_maybe_update_session($sessionRef, $processed, $totalLocations, $provisioned, $failed, $errors, $provisionedLocations);
        } catch (Exception $e) {
            $failed++;
            $errors[] = "Provisioning exception for {$locId}";
            $processed++;
            provision_maybe_update_session($sessionRef, $processed, $totalLocations, $provisioned, $failed, $errors, $provisionedLocations);
        }
    }

    $status = 'ready';
    if ($failed > 0 && $provisioned > 0) {
        $status = 'partial';
    } elseif ($failed > 0 && $provisioned === 0) {
        $status = 'failed';
    }

    $sessionRef->set([
        'status' => $status,
        'progress' => [
            'total_locations' => $totalLocations,
            'provisioned' => $provisioned,
            'failed' => $failed,
        ],
        'errors' => array_slice($errors, 0, 25),
        'provisioned_locations' => array_slice($provisionedLocations, 0, 100),
        'first_location' => $provisionedLocations[0] ?? null,
        'single_location' => count($provisionedLocations) === 1 ? $provisionedLocations[0] : null,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ], ['merge' => true]);

    echo json_encode([
        'ok' => true,
        'session_id' => $sessionId,
        'status' => $status,
        'progress' => ['total_locations' => $totalLocations, 'provisioned' => $provisioned, 'failed' => $failed],
        'provisioned_locations' => array_slice($provisionedLocations, 0, 100),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Provision failed']);
}
