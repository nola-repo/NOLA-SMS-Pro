<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$sessionId = trim((string)($_GET['session_id'] ?? ''));
$installToken = trim((string)($_GET['install_token'] ?? ''));
if ($sessionId === '' || $installToken === '') {
    http_response_code(422);
    echo json_encode(['error' => 'session_id and install_token are required']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}
$payload = jwt_verify($installToken, $jwtSecret);
if (!$payload || ($payload['type'] ?? '') !== 'agency_install') {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid install token']);
    exit;
}

$tokenSessionId = (string)($payload['session_id'] ?? '');
if ($tokenSessionId !== '' && $tokenSessionId !== $sessionId) {
    http_response_code(403);
    echo json_encode(['error' => 'Session mismatch']);
    exit;
}

try {
    $db = get_firestore();
    $snap = $db->collection('install_sessions')->document($sessionId)->snapshot();
    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Install session not found']);
        exit;
    }

    $d = $snap->data();
    $companyId = (string)($payload['company_id'] ?? '');
    if ($companyId !== '' && (string)($d['company_id'] ?? '') !== $companyId) {
        http_response_code(403);
        echo json_encode(['error' => 'Company mismatch']);
        exit;
    }

    echo json_encode([
        'session_id' => $sessionId,
        'company_id' => $d['company_id'] ?? null,
        'company_name' => $d['company_name'] ?? null,
        'status' => $d['status'] ?? 'pending',
        'progress' => $d['progress'] ?? ['total_locations' => 0, 'provisioned' => 0, 'failed' => 0],
        'errors' => $d['errors'] ?? [],
        'updated_at' => isset($d['updated_at']) && $d['updated_at'] instanceof \Google\Cloud\Core\Timestamp
            ? $d['updated_at']->get()->format('c')
            : null,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to read install status']);
}
