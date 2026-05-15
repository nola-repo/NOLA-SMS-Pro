<?php
/**
 * POST /api/auth/resolve-install-selection
 *
 * Continues an ambiguous GHL install only after the user explicitly selects a
 * candidate from a signed install session created by the OAuth callback.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../install_helpers.php';
require_once __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$sessionToken = trim((string)($input['session_token'] ?? ''));
$locationId = install_clean_location_id($input['location_id'] ?? null);
if ($sessionToken === '' || $locationId === null) {
    http_response_code(422);
    echo json_encode(['error' => 'A signed session_token and selected location_id are required.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

$payload = jwt_verify($sessionToken, (string)$jwtSecret);
if (!$payload || ($payload['type'] ?? '') !== 'install_selection_session') {
    http_response_code(401);
    echo json_encode(['error' => 'Install selection session is invalid or expired.']);
    exit;
}

$sessionId = trim((string)($payload['session_id'] ?? ''));
$companyId = trim((string)($payload['company_id'] ?? ''));
if ($sessionId === '' || $companyId === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Install selection session is missing required context.']);
    exit;
}

try {
    $db = get_firestore();
    $sessionRef = $db->collection('install_sessions')->document($sessionId);
    $sessionSnap = $sessionRef->snapshot();
    if (!$sessionSnap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Install selection session was not found.']);
        exit;
    }

    $session = $sessionSnap->data();
    if (($session['type'] ?? '') !== 'ambiguous_install_selection') {
        http_response_code(409);
        echo json_encode(['error' => 'Install session is not waiting for location selection.']);
        exit;
    }
    if ((string)($session['company_id'] ?? '') !== $companyId) {
        http_response_code(403);
        echo json_encode(['error' => 'Install session company mismatch.']);
        exit;
    }

    $candidateRows = is_array($session['candidate_locations'] ?? null) ? $session['candidate_locations'] : [];
    $candidateNames = [];
    $candidateIds = [];
    foreach ($candidateRows as $row) {
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

    if (!in_array($locationId, $candidateIds, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Selected location is not part of this signed install session.']);
        exit;
    }

    $claimRef = $db->collection('install_selection_claims')->document($sessionId);
    try {
        $claimRef->create([
            'session_id' => $sessionId,
            'company_id' => $companyId,
            'selected_location_id' => $locationId,
            'created_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ]);
    } catch (Exception $claimError) {
        $claimSnap = $claimRef->snapshot();
        if ($claimSnap->exists()) {
            $claim = $claimSnap->data();
            $claimedLocationId = install_clean_location_id($claim['selected_location_id'] ?? null);
            if ($claimedLocationId !== null && $claimedLocationId !== $locationId) {
                http_response_code(409);
                echo json_encode(['error' => 'This install session already selected a different subaccount. Please restart installation.']);
                exit;
            }
        }
    }

    if (install_location_company_mismatch($db, $locationId, $companyId)) {
        http_response_code(409);
        echo json_encode(['error' => 'Selected subaccount belongs to a different GoHighLevel company.']);
        exit;
    }

    $companySnap = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if (!$companySnap->exists()) {
        http_response_code(409);
        echo json_encode(['error' => 'Company token is missing. Please restart installation from GoHighLevel.']);
        exit;
    }
    $companyData = $companySnap->data();
    $companyToken = (string)($companyData['access_token'] ?? '');
    if ($companyToken === '') {
        http_response_code(409);
        echo json_encode(['error' => 'Company token is empty. Please restart installation from GoHighLevel.']);
        exit;
    }

    $tokenExistedBefore = install_token_doc_exists($db, $locationId);
    $exchange = install_exchange_location_token($companyToken, $companyId, $locationId);
    if (!$exchange['ok']) {
        http_response_code(502);
        echo json_encode([
            'error' => 'Failed to exchange selected subaccount token.',
            'details' => $exchange['failures'] ?? [],
        ]);
        exit;
    }

    $ltData = $exchange['data'];
    $now = new DateTimeImmutable();
    $expiresAt = time() + (int)($ltData['expires_in'] ?? 86400);
    $companyName = trim((string)($session['company_name'] ?? $payload['company_name'] ?? $companyData['company_name'] ?? $companyData['agency_name'] ?? ''));
    $locationName = install_fetch_location_name_with_token(
        (string)$ltData['access_token'],
        $locationId,
        (string)($candidateNames[$locationId] ?? '')
    );

    $clientId = $companyData['client_id'] ?? $companyData['appId'] ?? null;
    $db->collection('ghl_tokens')->document($locationId)->set([
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
        'install_resolution_source' => 'signed_install_selection',
        'provisioned_from_selection' => false,
        'selection_session_id' => $sessionId,
        'oauth_pending_started_at' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);

    $sessionRef->set([
        'status' => 'selected',
        'selected_location_id' => $locationId,
        'selected_location_name' => $locationName,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);

    $decision = install_decide_location_redirect(
        $db,
        (string)$jwtSecret,
        $locationId,
        $locationName,
        $companyId,
        $companyName,
        'signed_install_selection',
        $tokenExistedBefore
    );

    if (($decision['kind'] ?? '') === 'error' || empty($decision['url'])) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Selected subaccount could not be finalized.',
            'state' => $decision['status'] ?? INSTALL_STATE_INSTALL_PENDING,
        ]);
        exit;
    }

    install_finalize_location_install(
        $db,
        $locationId,
        $decision,
        INSTALL_RESOLUTION_EXACT_SINGLE_LOCATION,
        'signed_install_selection',
        $now
    );

    echo json_encode([
        'ok' => true,
        'kind' => $decision['kind'],
        'state' => $decision['status'],
        'install_state' => INSTALL_STATE_INSTALLED,
        'location_id' => $locationId,
        'location_name' => $locationName,
        'url' => $decision['url'],
    ]);
} catch (Exception $e) {
    error_log('[resolve_install_selection] exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to continue install selection.']);
}
