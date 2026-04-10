<?php
/**
 * POST /api/agency/ghl_autologin
 * GHL Iframe Auto-Login.
 *
 * Called by the Agency frontend when it detects a companyId in the iframe URL.
 * Looks up the agency account linked to that company_id and issues a JWT
 * WITHOUT requiring email/password — GHL context is the implicit auth.
 *
 * Payload:  { "company_id": "ABC123" }
 * Response: { token, role, company_id, user: {firstName,lastName,email} }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
$companyId = trim($input['company_id'] ?? '');

if (!$companyId) {
    error_log('[GHL_AUTOLOGIN] Attempted auto-login without company_id.');
    http_response_code(400);
    echo json_encode(['error' => 'company_id is required.']);
    exit;
}
error_log('[GHL_AUTOLOGIN] Attempting auto-login for company_id: ' . $companyId);

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

try {
    $db = get_firestore();

    // ── Find the agency account linked to this company_id ────────────────────
    $results = $db->collection('users')
        ->where('role',       '=', 'agency')
        ->where('company_id', '=', $companyId)
        ->limit(1)
        ->documents();

    $userId   = null;
    $userData = null;
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $userId   = $doc->id();
            $userData = $doc->data();
            break;
        }
    }

    if (!$userData) {
        error_log('[GHL_AUTOLOGIN] No existing agency user found for company_id: ' . $companyId . '. Creating new user doc on the fly.');
        // Automatically create a new user document on the fly
        $userData = [
            'role'       => 'agency',
            'company_id' => $companyId,
            'createdAt'  => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'active'     => true,
            'email'      => 'agency_' . $companyId . '@ghl.nolasmspro pro.com' // Placeholder email
        ];
        // Insert into firestore and get the auto-generated ID
        $newUserRef = $db->collection('users')->add($userData);
        $userId = $newUserRef->id();
    } else {
        error_log('[GHL_AUTOLOGIN] Found existing agency user for company_id: ' . $companyId . ' (User ID: ' . $userId . ')');
    }

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'This agency account has been deactivated.']);
        exit;
    }

    // ── Sign JWT (8 h) ────────────────────────────────────────────────────────
    $token = jwt_sign([
        'sub'        => $userId,
        'email'      => $userData['email'] ?? '',
        'role'       => 'agency',
        'company_id' => $companyId,
    ], $jwtSecret, 28800);

    echo json_encode([
        'token'      => $token,
        'role'       => 'agency',
        'company_id' => $companyId,
        'user'       => [
            'firstName' => $userData['firstName'] ?? '',
            'lastName'  => $userData['lastName']  ?? '',
            'email'     => $userData['email']      ?? '',
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Auto-login failed: ' . $e->getMessage()]);
}
