<?php
/**
 * POST /api/auth/resolve-install-selection
 *
 * Continues an ambiguous GHL install only after the user explicitly selects a
 * candidate from a signed install session created by the OAuth callback.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

@set_time_limit(45);

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
    $startedAt = microtime(true);
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

    $lockedPreselect = install_clean_location_id($session['preselected_location_id'] ?? null);
    $sessionUiMode = (string)($session['ui_mode'] ?? 'list');
    if ($lockedPreselect !== null) {
        if ($locationId !== $lockedPreselect) {
            http_response_code(403);
            echo json_encode(['error' => 'This install is locked to the sub-account you selected in GoHighLevel.']);
            exit;
        }
        $locationId = $lockedPreselect;
    }

    if (!in_array($locationId, $candidateIds, true)) {
        http_response_code(403);
        echo json_encode(['error' => 'Selected location is not part of this signed install session.']);
        exit;
    }

    if ($sessionUiMode === 'confirm_preselected' && count($candidateIds) === 1 && $candidateIds[0] !== $locationId) {
        http_response_code(403);
        echo json_encode(['error' => 'Install session candidate list does not match the Marketplace selection.']);
        exit;
    }

    $companySnap = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if (!$companySnap->exists()) {
        http_response_code(409);
        echo json_encode(['error' => 'Company token is missing. Please restart installation from GoHighLevel.']);
        exit;
    }
    $companyData = $companySnap->data();
    $companyName = trim((string)($session['company_name'] ?? $payload['company_name'] ?? $companyData['company_name'] ?? $companyData['agency_name'] ?? ''));

    try {
        install_record_selection_claim($db, $sessionId, $companyId, $locationId);
    } catch (RuntimeException $claimError) {
        http_response_code(409);
        echo json_encode(['error' => $claimError->getMessage()]);
        exit;
    }

    $result = install_complete_company_location_selection(
        $db,
        (string)$jwtSecret,
        $companyId,
        $companyName,
        $companyData,
        $locationId,
        (string)($candidateNames[$locationId] ?? ''),
        'signed_install_selection',
        $sessionId,
        true
    );

    if (!$result['ok'] || empty($result['url'])) {
        http_response_code(empty($result['decision']) ? 502 : 409);
        echo json_encode([
            'error' => $result['error'] ?? 'Selected subaccount could not be finalized.',
            'state' => $result['decision']['status'] ?? INSTALL_STATE_INSTALL_PENDING,
        ]);
        exit;
    }

    $decision = is_array($result['decision'] ?? null) ? $result['decision'] : [];
    $deferredFinalize = install_should_defer_finalize_for_decision($decision);

    error_log('[resolve_install_selection] ok locationId=' . $locationId . ' ms=' . (int)round((microtime(true) - $startedAt) * 1000));

    echo json_encode([
        'ok' => true,
        'kind' => $decision['kind'] ?? 'register',
        'state' => $decision['status'] ?? INSTALL_STATE_FRESH_INSTALL,
        'install_state' => $deferredFinalize ? INSTALL_STATE_PENDING_OAUTH : INSTALL_STATE_INSTALLED,
        'location_id' => $locationId,
        'location_name' => $candidateNames[$locationId] ?? '',
        'url' => $result['url'],
    ]);
} catch (Exception $e) {
    error_log('[resolve_install_selection] exception: ' . $e->getMessage() . ' ms=' . (isset($startedAt) ? (int)round((microtime(true) - $startedAt) * 1000) : 0));
    http_response_code(500);
    echo json_encode(['error' => 'Failed to continue install selection.']);
}
