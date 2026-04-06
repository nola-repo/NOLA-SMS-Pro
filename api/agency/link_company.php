<?php

/**
 * Link GHL Company ID
 *
 * Allows an authenticated agency user to link their GHL Company ID
 * without a full re-login. The frontend "Connect GHL Account" step calls this.
 *
 * Usage: POST /api/agency/link_company.php
 * Headers:
 *   Authorization: Bearer <jwt_token>
 *   X-Webhook-Secret: f7RkQ2pL9zV3tX8cB1nS4yW6
 *   Content-Type: application/json
 * Body: { "company_id": "GHL_COMPANY_123" }
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
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../webhook/firestore_client.php';

// 1. Validate API key
validate_api_request();

// 2. Validate JWT — extract user identity
$tokenPayload = validate_jwt();
$uid   = $tokenPayload['uid'] ?? null;
$role  = $tokenPayload['role'] ?? '';

if (!$uid) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
    exit;
}

// 3. Read and validate company_id from body
$input = json_decode(file_get_contents('php://input'), true);
$companyId = trim($input['company_id'] ?? '');

if (empty($companyId)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => 'company_id is required.']);
    exit;
}

try {
    $db = get_firestore();

    // 4. Find user document by UID
    $userRef = $db->collection('users')->document($uid);
    $userSnap = $userRef->snapshot();

    if (!$userSnap->exists()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found.']);
        exit;
    }

    // 5. Update user document with company_id
    $userRef->set([
        'company_id'  => $companyId,
        'updated_at'  => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ], ['merge' => true]);

    echo json_encode([
        'success'    => true,
        'company_id' => $companyId,
    ]);

} catch (Exception $e) {
    error_log('link_company error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error.']);
}
