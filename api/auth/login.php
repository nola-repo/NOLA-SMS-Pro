<?php

/**
 * Shared Login Endpoint
 *
 * Authenticates both Agency and User personas via email/password.
 * Returns a signed token and the user's role for frontend routing.
 *
 * Usage: POST /api/auth/login
 * Body:  { "email": "...", "password": "..." }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$email    = trim($input['email'] ?? '');
$password = $input['password'] ?? '';

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
    exit;
}

// Normalize email to lowercase
$email = strtolower($email);

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

try {
    // Query the users collection by email
    $query = $db->collection('users')->where('email', '=', $email)->limit(1);
    $documents = $query->documents();

    $userDoc = null;
    foreach ($documents as $doc) {
        if ($doc->exists()) {
            $userDoc = $doc;
            break;
        }
    }

    if (!$userDoc) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
        exit;
    }

    $userData = $userDoc->data();

    // Check if account is active
    if (isset($userData['active']) && !$userData['active']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Account is deactivated']);
        exit;
    }

    // Verify password
    $storedHash = $userData['password_hash'] ?? '';
    if (!password_verify($password, $storedHash)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or password']);
        exit;
    }

    // Build token payload
    $role = $userData['role'] ?? 'user';
    $tokenPayload = [
        'uid'  => $userDoc->id(),
        'email' => $email,
        'role'  => $role,
        'iat'   => time(),
        'exp'   => time() + (60 * 60 * 24 * 7), // 7-day expiry
    ];

    // Add role-specific identifiers
    if ($role === 'agency') {
        if (!empty($userData['agency_id'])) {
            $tokenPayload['agency_id'] = $userData['agency_id'];
        }
        // Include company_id in token so agency endpoints can scope data
        // without requiring the frontend to send X-Agency-ID headers separately
        if (!empty($userData['company_id'])) {
            $tokenPayload['company_id'] = $userData['company_id'];
        } elseif (!empty($userData['agency_id'])) {
            // Fallback: treat agency_id as company_id for legacy records
            $tokenPayload['company_id'] = $userData['agency_id'];
        }
    }
    if ($role === 'user' && !empty($userData['location_id'])) {
        $tokenPayload['location_id'] = $userData['location_id'];
    }

    // Sign the token with HMAC-SHA256
    $secret = getenv('AUTH_TOKEN_SECRET') ?: 'nola-sms-pro-auth-secret-2026';
    $payloadB64 = rtrim(strtr(base64_encode(json_encode($tokenPayload)), '+/', '-_'), '=');
    $signature  = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
    $token = $payloadB64 . '.' . $signature;

    // Update last login timestamp
    $userDoc->reference()->set([
        'last_login_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ], ['merge' => true]);

    echo json_encode([
        'token'       => $token,
        'role'        => $role,
        'company_id'  => $userData['company_id'] ?? $userData['agency_id'] ?? null,
        'location_id' => $role === 'user' ? ($userData['active_location_id'] ?? $userData['location_id'] ?? null) : null,
        'user'        => [
            'firstName'   => $userData['firstName'] ?? '',
            'lastName'    => $userData['lastName'] ?? '',
            'email'       => $email,
            'max_active_subaccounts' => $role === 'agency' ? ($userData['max_active_subaccounts'] ?? 3) : null
        ],
    ]);

} catch (Exception $e) {
    error_log('Login endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
