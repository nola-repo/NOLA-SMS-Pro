<?php
/**
 * POST /api/auth/login
 * Email + password login for Agency and User accounts.
 * Returns a signed JWT with role, company_id / location_id.
 *
 * Response 200: { token, role, company_id, location_id, user: {firstName,lastName,email} }
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

$input    = json_decode(file_get_contents('php://input'), true);
$email    = strtolower(trim($input['email']    ?? ''));
$password = $input['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

try {
    $db = get_firestore();

    // ── Find user by email ───────────────────────────────────────────────────
    $results = $db->collection('users')
        ->where('email', '=', $email)
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
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    // ── Verify password ──────────────────────────────────────────────────────
    $storedHash = $userData['password_hash'] ?? '';
    if (!password_verify($password, $storedHash)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account has been deactivated.']);
        exit;
    }

    $role      = $userData['role']       ?? 'user';
    $companyId = $userData['company_id'] ?? null;
    $locationId= $userData['active_location_id'] ?? null;

    // ── Sign JWT ─────────────────────────────────────────────────────────────
    $token = jwt_sign([
        'sub'        => $userId,
        'email'      => $email,
        'role'       => $role,
        'company_id' => $companyId,
    ], $jwtSecret, 28800); // 8 hours

    echo json_encode([
        'token'       => $token,
        'role'        => $role,
        'company_id'  => $companyId,
        'location_id' => $locationId,
        'user'        => [
            'firstName' => $userData['firstName'] ?? '',
            'lastName'  => $userData['lastName']  ?? '',
            'email'     => $email,
            'phone'     => $userData['phone'] ?? '',
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Login failed: ' . $e->getMessage()]);
}
