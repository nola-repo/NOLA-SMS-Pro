<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    require_once __DIR__ . '/admin_auth_helper.php';
    $claims = require_secure_admin_auth();
    $email = strtolower(trim((string)($claims['email'] ?? $claims['username'] ?? '')));
    
    $db = get_firestore();
    try {
        $adminRef = $db->collection('admins')->document($email);
        $snap = $adminRef->snapshot();

        if (!$snap->exists()) {
            $matches = $db->collection('admins')
                ->where('email', '=', $email)
                ->limit(1)
                ->documents();

            foreach ($matches as $doc) {
                if ($doc->exists()) {
                    $snap = $doc;
                    break;
                }
            }
        }

        if ($snap->exists()) {
            $adminData = $snap->data();
            echo json_encode([
                'status' => 'success',
                'name' => $adminData['name'] ?? $adminData['full_name'] ?? '',
                'full_name' => $adminData['full_name'] ?? $adminData['name'] ?? '',
                'email' => $adminData['email'] ?? $email,
                'role' => $adminData['role'] ?? 'viewer',
                'phone' => $adminData['phone'] ?? $adminData['phone_number'] ?? ''
            ]);
        } else {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'Admin not found']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
    }
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim($input['email'] ?? $input['username'] ?? ''));
$password = $input['password'] ?? '';
$rememberMe = !empty($input['remember_me']) || !empty($input['rememberMe']);

if (empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
    exit;
}

$db = get_firestore();

try {
    $adminRef = $db->collection('admins')->document($email);
    $snapshot = $adminRef->snapshot();

    if (!$snapshot->exists()) {
        $legacyMatches = $db->collection('admins')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();

        foreach ($legacyMatches as $legacyDoc) {
            if ($legacyDoc->exists()) {
                $snapshot = $legacyDoc;
                break;
            }
        }
    }

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
        $secret = getenv('JWT_SECRET');
        if ($secret === false || trim((string) $secret) === '') {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Server misconfiguration: JWT secret missing.']);
            exit;
        }
        $tokenTtl = $rememberMe ? 60 * 60 * 24 * 30 : 28800;
        $adminName = $adminData['full_name'] ?? $adminData['name'] ?? '';
        $token = jwt_sign([
            'email' => $email,
            'username' => $email,
            'role' => $adminData['role'] ?? 'admin',
            'name' => $adminName,
            'full_name' => $adminName
        ], $secret, $tokenTtl);

        // Successful login
        echo json_encode([
            'status' => 'success',
            'message' => 'Login successful',
            'token' => $token,
            'expires_in' => $tokenTtl,
            'remembered' => $rememberMe,
            'user' => [
                'email' => $email,
                'username' => $email,
                'role' => $adminData['role'] ?? 'admin',
                'name' => $adminName,
                'full_name' => $adminName
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
