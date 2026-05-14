<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../install_helpers.php';
require_once __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$sessionId = trim((string)($_GET['session_id'] ?? ''));
$installToken = trim((string)($_GET['install_token'] ?? ''));
if ($sessionId === '' || $installToken === '') {
    http_response_code(422);
    echo json_encode(['error' => 'session_id and install_token are required']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}
$payload = jwt_verify($installToken, $jwtSecret);
$tokenType = (string)($payload['type'] ?? '');
if (!$payload || !in_array($tokenType, ['agency_install', 'bulk_install_session'], true)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid install token']);
    exit;
}

$tokenSessionId = (string)($payload['session_id'] ?? '');
if ($tokenSessionId !== '' && $tokenSessionId !== $sessionId) {
    http_response_code(403);
    echo json_encode(['error' => 'Session mismatch']);
    exit;
}

function install_status_locations_from_session(array $session): array
{
    $locations = [];
    $seen = [];
    $rawRows = [];

    if (isset($session['provisioned_locations']) && is_array($session['provisioned_locations'])) {
        $rawRows = array_merge($rawRows, $session['provisioned_locations']);
    }
    foreach (['single_location', 'first_location'] as $key) {
        if (isset($session[$key]) && is_array($session[$key])) {
            $rawRows[] = $session[$key];
        }
    }

    foreach ($rawRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $locationId = install_clean_location_id($row['location_id'] ?? $row['locationId'] ?? $row['id'] ?? null);
        if ($locationId === null || isset($seen[$locationId])) {
            continue;
        }
        $seen[$locationId] = true;
        $locations[] = [
            'location_id' => $locationId,
            'location_name' => trim((string)($row['location_name'] ?? $row['locationName'] ?? $row['name'] ?? '')),
        ];
    }

    return $locations;
}

function install_status_infer_single_location_for_company($db, string $companyId): ?array
{
    $companyId = trim($companyId);
    if ($companyId === '') {
        return null;
    }

    $locations = [];
    try {
        $docs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            if (($data['appType'] ?? '') === 'agency' || $doc->id() === $companyId) {
                continue;
            }
            $locationId = install_clean_location_id($data['location_id'] ?? $data['locationId'] ?? $doc->id());
            if ($locationId === null || $locationId === $companyId) {
                continue;
            }
            $locations[$locationId] = [
                'location_id' => $locationId,
                'location_name' => trim((string)($data['location_name'] ?? $data['locationName'] ?? '')),
            ];
        }
    } catch (Exception $e) {
        error_log('[install_status] single-location inference failed: ' . $e->getMessage());
        return null;
    }

    if (count($locations) !== 1) {
        return null;
    }

    return array_values($locations)[0];
}

function install_status_next_action($db, string $jwtSecret, array $session, array $payload): array
{
    $status = (string)($session['status'] ?? 'pending');
    $progress = is_array($session['progress'] ?? null) ? $session['progress'] : [];
    $provisioned = (int)($progress['provisioned'] ?? 0);
    $failed = (int)($progress['failed'] ?? 0);
    $locations = install_status_locations_from_session($session);
    $companyId = trim((string)($session['company_id'] ?? $payload['company_id'] ?? ''));
    $companyName = trim((string)($session['company_name'] ?? $payload['company_name'] ?? ''));

    if (in_array($status, ['pending', 'provisioning'], true)) {
        return [
            'kind' => 'wait',
            'label' => 'Provisioning',
            'message' => 'Provisioning is still running.',
        ];
    }

    if ($status === 'failed' || ($provisioned === 0 && $failed > 0)) {
        return [
            'kind' => 'failed',
            'label' => 'Provisioning failed',
            'message' => 'No sub-account tokens were provisioned. Please reinstall or check the server logs.',
        ];
    }

    if ($provisioned === 1 && count($locations) < 1 && $companyId !== '') {
        $inferredLocation = install_status_infer_single_location_for_company($db, $companyId);
        if ($inferredLocation !== null) {
            $locations[] = $inferredLocation;
        }
    }

    if ($provisioned === 1 && count($locations) >= 1) {
        $location = $locations[0];
        $decision = install_decide_location_redirect(
            $db,
            $jwtSecret,
            (string)$location['location_id'],
            (string)$location['location_name'],
            $companyId !== '' ? $companyId : null,
            $companyName,
            'bulk_status_single_location'
        );

        if (($decision['kind'] ?? '') === 'error') {
            return [
                'kind' => 'error',
                'label' => 'Sub-account mismatch',
                'message' => 'The provisioned sub-account does not match the saved GoHighLevel company.',
                'location_id' => $location['location_id'],
                'location_name' => $location['location_name'],
            ];
        }

        return [
            'kind' => $decision['kind'],
            'label' => $decision['kind'] === 'login' ? 'Continue to sign in' : 'Continue setup',
            'message' => $decision['kind'] === 'login'
                ? 'This sub-account is already registered. Continue to sign in.'
                : 'This single sub-account is ready. Continue to account setup.',
            'url' => $decision['url'],
            'location_id' => $location['location_id'],
            'location_name' => $location['location_name'],
            'install_status' => $decision['status'],
        ];
    }

    if ($provisioned > 1) {
        return [
            'kind' => 'open_subaccount',
            'label' => 'Open from GHL',
            'message' => 'Multiple sub-accounts were provisioned. Open NOLA SMS Pro from inside the target GoHighLevel sub-account to complete that sub-account registration.',
        ];
    }

    return [
        'kind' => 'complete',
        'label' => 'Install complete',
        'message' => 'Provisioning completed, but no sub-account registration target was returned.',
    ];
}

try {
    $db = get_firestore();
    $snap = $db->collection('install_sessions')->document($sessionId)->snapshot();
    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Install session not found']);
        exit;
    }

    $d = $snap->data();
    $companyId = (string)($payload['company_id'] ?? '');
    if ($companyId !== '' && (string)($d['company_id'] ?? '') !== $companyId) {
        http_response_code(403);
        echo json_encode(['error' => 'Company mismatch']);
        exit;
    }

    $locations = install_status_locations_from_session($d);
    echo json_encode([
        'session_id' => $sessionId,
        'company_id' => $d['company_id'] ?? null,
        'company_name' => $d['company_name'] ?? null,
        'status' => $d['status'] ?? 'pending',
        'progress' => $d['progress'] ?? ['total_locations' => 0, 'provisioned' => 0, 'failed' => 0],
        'errors' => $d['errors'] ?? [],
        'provisioned_locations' => array_slice($locations, 0, 20),
        'next_action' => install_status_next_action($db, (string)$jwtSecret, $d, $payload),
        'updated_at' => isset($d['updated_at']) && $d['updated_at'] instanceof \Google\Cloud\Core\Timestamp
            ? $d['updated_at']->get()->format('c')
            : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read install status']);
}
