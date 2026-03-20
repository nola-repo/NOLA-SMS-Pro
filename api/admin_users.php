<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// Authentication: Admin-only secret
validate_api_request();

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $admins = $db->collection('admins')->documents();
    $results = [];

    foreach ($admins as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            // Security: Remove sensitive fields
            unset($data['password']);
            unset($data['hashed_password']);
            
            $data['username'] = $doc->id();
            
            // Format timestamp if present
            if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
                $data['created_at'] = $data['created_at']->get()->format('Y-m-d');
            }

            $results[] = $data;
        }
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}

if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    $username = $payload['username'] ?? null;
    $password = $payload['password'] ?? null;
    $role = $payload['role'] ?? 'support';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username and password are required.']);
        exit;
    }

    $adminRef = $db->collection('admins')->document($username);
    if ($adminRef->snapshot()->exists()) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Username already exists.']);
        exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
    $adminRef->set([
        'hashed_password' => $hashedPassword,
        'role' => $role,
        'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Admin added successfully.']);
    exit;
}

if ($method === 'DELETE') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);

    $username = $payload['username'] ?? null;

    if (!$username) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username is required.']);
        exit;
    }

    $adminRef = $db->collection('admins')->document($username);
    if (!$adminRef->snapshot()->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Admin not found.']);
        exit;
    }

    $adminRef->delete();

    echo json_encode(['status' => 'success', 'message' => 'Admin deleted successfully.']);
    exit;
}

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed.']);
