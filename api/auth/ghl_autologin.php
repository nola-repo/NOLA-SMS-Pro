<?php
/**
 * POST /api/auth/ghl_autologin
 * GHL Iframe Sub-Account Auto-Login.
 *
 * Called by the User Panel frontend when it detects a location_id in the
 * iframe URL that does not match the current session. Looks up the NOLA
 * user linked to that location_id and issues a JWT WITHOUT requiring
 * email/password — the GHL iframe context is the implicit auth.
 *
 * Payload:  { "location_id": "V52Lp7YQo1ISiSf907Lu" }
 * Response 200: { token, role, company_id, location_id,
 *                 user: { firstName, lastName, email, location_id, … } }
 * Response 404: { error: "…" }   — no NOLA account linked to this location
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/user_profile_helper.php';
require_once __DIR__ . '/../install_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input      = json_decode(file_get_contents('php://input'), true);
$locationId = trim((string)($input['location_id'] ?? ''));

if ($locationId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'location_id is required.']);
    exit;
}

error_log('[GHL_AUTOLOGIN_LOCATION] Attempting auto-login for location_id: ' . $locationId);

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

try {
    $db = get_firestore();

    // ── Step 1: Find the linked account for this location ────────────────────
    $linked = install_linked_account_for_location($db, $locationId, true);

    if ($linked === null || empty($linked['id'])) {
        error_log('[GHL_AUTOLOGIN_LOCATION] No linked NOLA account for location_id: ' . $locationId);
        http_response_code(404);
        echo json_encode([
            'error'       => 'No NOLA SMS Pro account is linked to this GHL sub-account. Please register first.',
            'location_id' => $locationId,
        ]);
        exit;
    }

    $userId = $linked['id'];
    error_log('[GHL_AUTOLOGIN_LOCATION] Found linked account user_id: ' . $userId . ' for location_id: ' . $locationId);

    // ── Step 2: Fetch the full user document ─────────────────────────────────
    $userSnap = $db->collection('users')->document($userId)->snapshot();
    if (!$userSnap->exists()) {
        error_log('[GHL_AUTOLOGIN_LOCATION] User doc missing for user_id: ' . $userId);
        http_response_code(404);
        echo json_encode(['error' => 'Linked user account not found.']);
        exit;
    }

    $userData = $userSnap->data();

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'This account has been deactivated.']);
        exit;
    }

    $role      = (string)($userData['role'] ?? 'user');
    $companyId = $userData['company_id'] ?? null;

    // Resolve authoritative location_id from the user doc (may differ from request if legacy)
    $resolvedLocationId = $userData['active_location_id']
        ?? $userData['location_id']
        ?? $userData['locationId']
        ?? $locationId;

    // ── Step 3: Sign JWT (8 hours) ───────────────────────────────────────────
    $token = jwt_sign([
        'sub'             => $userId,
        'email'           => $userData['email'] ?? ($linked['email'] ?? ''),
        'role'            => $role,
        'company_id'      => $companyId,
        'location_id'     => $resolvedLocationId,
        'auth_collection' => 'users',
    ], $jwtSecret, 28800);

    error_log('[GHL_AUTOLOGIN_LOCATION] JWT issued for user_id: ' . $userId . ' location_id: ' . $resolvedLocationId);

    echo json_encode([
        'token'       => $token,
        'role'        => $role,
        'company_id'  => $companyId,
        'location_id' => $resolvedLocationId,
        'user'        => auth_user_payload_for_api($userData, $linked['email'] ?? ''),
    ]);

} catch (Exception $e) {
    error_log('[GHL_AUTOLOGIN_LOCATION] Exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Auto-login failed: ' . $e->getMessage()]);
}
