<?php
/**
 * GET /api/auth/verify-install-token?token=<install_token>
 *
 * Decodes and validates a short-lived install JWT issued by ghl_callback.php
 * or ghl_agency_callback.php after a successful GHL Marketplace installation.
 *
 * Returns the pre-fill data (location_id, location_name, company_id) so the
 * React registration form can auto-fill locked fields without re-doing OAuth.
 *
 * The token is NOT consumed here — it is consumed (and optionally recorded)
 * when the user actually submits register-from-install.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require_once __DIR__ . '/../jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$token = trim($_GET['token'] ?? '');
if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing token parameter']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

$payload = jwt_verify($token, $jwtSecret);

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Install token is invalid or has expired. Please reinstall the app from the GHL Marketplace.']);
    exit;
}

// Must be an install token (not a regular auth token)
$type = $payload['type'] ?? '';
if ($type !== 'install' && $type !== 'agency_install') {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token type.']);
    exit;
}

// Return only the pre-fill fields needed by the registration form
echo json_encode([
    'type'          => $type,
    'location_id'   => $payload['location_id']   ?? null,
    'location_name' => $payload['location_name'] ?? null,
    'company_id'    => $payload['company_id']    ?? null,
    'company_name'  => $payload['company_name']  ?? null,
]);
