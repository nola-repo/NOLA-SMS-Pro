<?php

/**
 * Register Endpoint
 *
 * Registers both agency and user accounts.
 *
 * Usage: POST /api/auth/register.php
 * Body: { "firstName": "...", "lastName": "...", "email": "...", "password": "...", "role": "agency" }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$firstName = trim($input['firstName'] ?? '');
$lastName  = trim($input['lastName'] ?? '');
$email     = trim($input['email'] ?? '');
$phone     = trim($input['phone'] ?? '');
$password  = $input['password'] ?? '';
$role      = trim($input['role'] ?? 'user');
$companyId = trim($input['company_id'] ?? '');
$locationId = trim($input['location_id'] ?? '');

if (empty($firstName) || empty($lastName) || empty($email) || empty($password)) {
    http_response_code(422);
    echo json_encode(['status' => 'error', 'message' => 'All fields are required.']);
    exit;
}

if (!in_array($role, ['user', 'agency'])) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid role']);
    exit;
}

$email = strtolower($email);

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

try {
    // Check if email already exists
    $query = $db->collection('users')->where('email', '=', $email)->limit(1);
    $documents = $query->documents();
    
    $exists = false;
    foreach ($documents as $doc) {
        if ($doc->exists()) {
            $exists = true;
            break;
        }
    }

    if ($exists) {
        http_response_code(409);
        echo json_encode(['status' => 'error', 'message' => 'Email already registered.']);
        exit;
    }

    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $now = new \Google\Cloud\Core\Timestamp(new DateTimeImmutable());

    $data = [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'name' => trim($firstName . ' ' . $lastName),
        'email' => $email,
        'phone' => $phone,
        'password_hash' => $passwordHash,
        'role' => $role,
        'active' => true,
        'created_at' => $now,
        'updated_at' => $now,
        'company_id' => null
    ];

    if ($role === 'agency' && !empty($companyId)) {
        $data['company_id'] = $companyId;
        // Map to agency_id for backwards compatibility with existing logic
        $data['agency_id'] = $companyId;
    }
    
    if ($role === 'user' && !empty($locationId)) {
        $data['location_id'] = $locationId;
        $data['active_location_id'] = $locationId;
    }

    $db->collection('users')->add($data);

    http_response_code(201);
    echo json_encode(['status' => 'success', 'message' => 'Account created.']);

} catch (Exception $e) {
    error_log('Register endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
