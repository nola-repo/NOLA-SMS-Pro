<?php

/**
 * GHL Agency Auto-Login
 *
 * Called by the Agency frontend when it detects a companyId in the GHL iframe URL.
 * Looks up the agency user by company_id and returns a signed JWT — no password needed.
 *
 * Usage: POST /api/agency/ghl_autologin
 * Body:  { "company_id": "ABC123" }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$companyId = trim($input['company_id'] ?? '');

if (empty($companyId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'company_id is required']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

try {
    // Query users collection for an agency with this company_id
    $query = $db->collection('users')
        ->where('role', '=', 'agency')
        ->where('company_id', '=', $companyId)
        ->limit(1);
    $documents = $query->documents();

    $userDoc = null;
    foreach ($documents as $doc) {
        if ($doc->exists()) {
            $userDoc = $doc;
            break;
        }
    }

    if (!$userDoc) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'error' => 'No agency account is linked to this GoHighLevel company.']);
        exit;
    }

    $userData = $userDoc->data();

    // Check if account is active
    if (isset($userData['active']) && !$userData['active']) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'error' => 'This agency account has been deactivated.']);
        exit;
    }

    // Build token payload (8-hour expiry for iframe sessions)
    $tokenPayload = [
        'uid'        => $userDoc->id(),
        'email'      => $userData['email'] ?? '',
        'role'       => 'agency',
        'company_id' => $companyId,
        'iat'        => time(),
        'exp'        => time() + (60 * 60 * 8), // 8h
    ];

    if (!empty($userData['agency_id'])) {
        $tokenPayload['agency_id'] = $userData['agency_id'];
    }

    // Sign the token with HMAC-SHA256 (same scheme as login.php)
    $secret = getenv('AUTH_TOKEN_SECRET') ?: 'nola-sms-pro-auth-secret-2026';
    $payloadB64 = rtrim(strtr(base64_encode(json_encode($tokenPayload)), '+/', '-_'), '=');
    $signature  = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');
    $token = $payloadB64 . '.' . $signature;

    // Update last login timestamp
    $userDoc->reference()->set([
        'last_login_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ], ['merge' => true]);

    echo json_encode([
        'token'      => $token,
        'role'       => 'agency',
        'company_id' => $companyId,
        'user'       => [
            'firstName'              => $userData['firstName'] ?? '',
            'lastName'               => $userData['lastName'] ?? '',
            'email'                  => $userData['email'] ?? '',
            'max_active_subaccounts' => $userData['max_active_subaccounts'] ?? 3,
        ],
    ]);

} catch (Exception $e) {
    error_log('ghl_autologin error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
