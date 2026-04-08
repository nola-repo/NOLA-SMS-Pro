<?php
/**
 * POST /api/agency/link_company
 * Links a GHL company_id to an authenticated agency account.
 * Called after manual entry or after GHL OAuth completes during registration.
 *
 * Headers: Authorization: Bearer <token>
 * Payload: { "company_id": "ABC123" }
 * Response 200: { status: "success" }
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

// ── Verify JWT ────────────────────────────────────────────────────────────────
$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!$authHeader) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $k => $v) {
        if (strcasecmp($k, 'Authorization') === 0) { $authHeader = $v; break; }
    }
}

$bearerToken = '';
if (preg_match('/Bearer\s+(.+)/i', $authHeader, $m)) {
    $bearerToken = $m[1];
}

if (!$bearerToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Authorization token required.']);
    exit;
}

$claims = jwt_verify($bearerToken, $jwtSecret);
if (!$claims) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid or expired token.']);
    exit;
}

if (($claims['role'] ?? '') !== 'agency') {
    http_response_code(403);
    echo json_encode(['error' => 'Only agency accounts can link a company.']);
    exit;
}

$userId = $claims['sub'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Token missing user ID.']);
    exit;
}

// ── Read payload ──────────────────────────────────────────────────────────────
$input     = json_decode(file_get_contents('php://input'), true);
$companyId = trim($input['company_id'] ?? '');

if (!$companyId) {
    http_response_code(400);
    echo json_encode(['error' => 'company_id is required.']);
    exit;
}

try {
    $db  = get_firestore();
    $now = new DateTimeImmutable();

    $db->collection('users')
        ->document($userId)
        ->set(['company_id' => $companyId, 'updatedAt' => new \Google\Cloud\Core\Timestamp($now)], ['merge' => true]);

    echo json_encode(['status' => 'success', 'company_id' => $companyId]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to link company: ' . $e->getMessage()]);
}
