<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';
$password = $input['password'] ?? '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Username and password are required']);
    exit;
}

$db = get_firestore();

try {
    $adminRef = $db->collection('admins')->document($username);
    $snapshot = $adminRef->snapshot();

    if (!$snapshot->exists()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
        exit;
    }

    $adminData = $snapshot->data();
    
    if (empty($adminData['active'])) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Account is deactivated.']);
        exit;
    }

    $storedHash = $adminData['password_hash'] ?? $adminData['hashed_password'] ?? '';

    if (password_verify($password, $storedHash)) {
        // Generate JWT
        $secret = getenv('JWT_SECRET') ?: 'nola-super-admin-secret';
        $token = jwt_sign([
            'username' => $username,
            'role' => $adminData['role'] ?? 'admin'
        ], $secret);

        // Successful login
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $token,
            'user' => [
                'username' => $username,
                'role' => $adminData['role'] ?? 'admin'
            ]
        ]);
        exit;
    } else {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid credentials']);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
    exit;
}
