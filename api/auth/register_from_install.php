<?php
/**
 * POST /api/auth/register-from-install
 * Handles First-Run Registration from the GHL Marketplace installation callback.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$fullName   = trim($input['full_name'] ?? '');
$phone      = trim($input['phone'] ?? '');
$email      = strtolower(trim($input['email'] ?? ''));
$password   = $input['password'] ?? '';
$locationId = $input['location_id'] ?? null;
$companyId  = $input['company_id'] ?? null;

if (!$fullName || !$phone || !$email || !$password) {
    http_response_code(422);
    echo json_encode(['error' => 'All fields are required.']);
    exit;
}

if (strlen($password) < 8) {
    http_response_code(422);
    echo json_encode(['error' => 'Password must be at least 8 characters.']);
    exit;
}

// Split full name
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? '';

try {
    $db = get_firestore();
    $now = new DateTimeImmutable();

    // 1. Check if email already exists
    $usersRef = $db->collection('users');
    $existingQuery = $usersRef->where('email', '=', $email)->limit(1)->documents();
    $existingDoc = null;
    $existingUserId = null;

    foreach ($existingQuery as $docSnap) {
        if ($docSnap->exists()) {
            $existingDoc = $docSnap->data();
            $existingUserId = $docSnap->id();
            break;
        }
    }

    $isLocationLevel = !empty($locationId);

    if ($existingDoc) {
        // User exists
        $updates = ['updated_at' => new \Google\Cloud\Core\Timestamp($now)];
        $linked = false;
        
        if ($isLocationLevel) {
            $updates['active_location_id'] = $locationId;
            if (($existingDoc['active_location_id'] ?? '') !== $locationId) {
                // Link this new location to the existing account
                $linked = true;
            }
        } else if (!empty($companyId)) {
            $updates['company_id'] = $companyId;
            if (($existingDoc['company_id'] ?? '') !== $companyId) {
                $linked = true;
            }
        }

        $usersRef->document($existingUserId)->set($updates, ['merge' => true]);

        // Proceed to update integration record
        update_integration_record($db, $locationId, $email, $fullName, $phone, $now);

        echo json_encode([
            'status' => 'linked',
            'message' => 'Account already exists. Location linked.'
        ]);
        exit;
    }

    // 2. Create new user
    $role = ($isLocationLevel) ? 'user' : 'agency';
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    
    // We create a new doc ref
    $newUserDoc = $usersRef->newDocument();
    
    $userData = [
        'firstName' => $firstName,
        'lastName' => $lastName,
        'email' => $email,
        'phone' => $phone,
        'password_hash' => $passwordHash,
        'role' => $role,
        'active' => true,
        'source' => 'marketplace_install',
        'createdAt' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now)
    ];

    if ($isLocationLevel) {
        $userData['active_location_id'] = $locationId;
    }
    if (!empty($companyId)) {
        $userData['company_id'] = $companyId;
        // Also if it's an agency register, agency_id might also be company_id for some backwards compat
        if ($role === 'agency') {
            $userData['agency_id'] = $companyId;
        }
    }

    $newUserDoc->set($userData);

    // 3. Update integrations record if location level
    update_integration_record($db, $locationId, $email, $fullName, $phone, $now);

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Account ready.'
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}

function update_integration_record($db, $locationId, $email, $fullName, $phone, $now) {
    if (!$locationId) return;

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locationId);
    $integrationRef = $db->collection('integrations')->document($intDocId);
    
    if ($integrationRef->snapshot()->exists()) {
        $integrationRef->set([
            'owner_email' => $email,
            'owner_name'  => $fullName,
            'owner_phone' => $phone,
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now)
        ], ['merge' => true]);
    }
}
