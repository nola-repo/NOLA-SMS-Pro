<?php
/**
 * GET /api/auth/me
 * Returns the authenticated user's full profile from Firestore.
 * Used by the frontend to self-heal stale nola_user localStorage cache.
 *
 * Requires: Authorization: Bearer <token>
 * Response 200: { user: { firstName, lastName, email, phone, location_id, location_name, company_name, company_id } }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing or invalid Authorization header.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$payload   = jwt_verify($m[1], $jwtSecret);

if (!$payload) {
    http_response_code(401);
    echo json_encode(['error' => 'Token is invalid or expired.']);
    exit;
}

$userId = $payload['sub'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token payload.']);
    exit;
}

try {
    $db   = get_firestore();
    $snap = $db->collection('users')->document($userId)->snapshot();

    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'User not found.']);
        exit;
    }

    $d = $snap->data();

    echo json_encode([
        'user' => [
            'name'         => $d['name']
                              ?? trim(($d['firstName'] ?? '') . ' ' . ($d['lastName'] ?? ''))
                              ?: ($d['email'] ?? ''),
            'email'        => $d['email']              ?? '',
            'phone'        => $d['phone']              ?? '',
            'location_id'  => $d['active_location_id'] ?? null,
            'company_id'   => $d['company_id']         ?? null,
            'location_name'=> $d['location_name']      ?? null,
            'company_name' => $d['company_name']       ?? null,
            'location_memberships' => $d['location_memberships'] ?? [],
        ],
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch profile: ' . $e->getMessage()]);
}
