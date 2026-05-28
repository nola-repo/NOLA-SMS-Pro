<?php
/**
 * POST /api/auth/login
 * Email + password login for Agency and User accounts.
 * Returns a signed JWT with role, company_id / location_id.
 *
 * Response 200: { token, role, company_id, location_id, user: {firstName,lastName,email} }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/user_profile_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input    = json_decode(file_get_contents('php://input'), true);
$email    = strtolower(trim($input['email']    ?? ''));
$password = $input['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password are required.']);
    exit;
}

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

try {
    $db = get_firestore();

    // ── Prefer agency_users for agency accounts, fallback to users ───────────
    $results = $db->collection('agency_users')
        ->where('email', '=', $email)
        ->limit(1)
        ->documents();

    $userId   = null;
    $userData = null;
    $authCollection = 'agency_users';
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $userId   = $doc->id();
            $userData = $doc->data();
            break;
        }
    }

    if (!$userData) {
        $authCollection = 'users';
        $results = $db->collection('users')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();
        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userId   = $doc->id();
                $userData = $doc->data();
                break;
            }
        }
    }

    if (!$userData) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    // ── Verify password ──────────────────────────────────────────────────────
    $storedHash = $userData['password_hash'] ?? '';
    if (!password_verify($password, $storedHash)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Your account has been deactivated.']);
        exit;
    }

    $role        = $userData['role']               ?? 'user';
    $companyId   = $userData['company_id']          ?? null;
    $locationId  = $userData['active_location_id'] ?? null;

    if ($locationId === null) {
        if (!empty($userData['location_id'])) {
            error_log("[api/auth/login.php] legacy location_id used for {$email}");
            $locationId = $userData['location_id'];
        } elseif (!empty($userData['locationId'])) {
            error_log("[api/auth/login.php] legacy locationId used for {$email}");
            $locationId = $userData['locationId'];
        }
    }

    $reqLocationId = trim((string)($input['location_id'] ?? ''));
    if ($reqLocationId !== '' && $role !== 'agency') {
        if (empty($companyId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Login failed: user location metadata is incomplete.']);
            exit;
        }

        $locationDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $reqLocationId);
        $locationSnap = $db->collection('integrations')->document($locationDocId)->snapshot();
        if ($locationSnap->exists()) {
            $locationData = $locationSnap->data();
            $locationCompanyId = $locationData['companyId'] ?? $locationData['company_id'] ?? null;
            if ($locationCompanyId !== null && (string)$locationCompanyId !== (string)$companyId) {
                http_response_code(403);
                echo json_encode(['error' => 'Login failed: location is not authorized for this account.']);
                exit;
            }
        }

        // Update user active_location_id in Firestore
        try {
            $db->collection('users')->document($userId)->set([
                'active_location_id' => $reqLocationId,
                'updatedAt' => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ], ['merge' => true]);
            $locationId = $reqLocationId;
            $userData['active_location_id'] = $reqLocationId;
            error_log("[api/auth/login.php] Updated user {$email} active_location_id to {$reqLocationId}");
        } catch (Exception $e) {
            error_log("[api/auth/login.php] Failed to update user active_location_id: " . $e->getMessage());
        }
    }

    if ($locationId !== null && $role !== 'agency') {
        if (empty($companyId)) {
            http_response_code(400);
            echo json_encode(['error' => 'Login failed: user location metadata is incomplete.']);
            exit;
        }

        $locationDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locationId);
        $locationSnap = $db->collection('integrations')->document($locationDocId)->snapshot();
        if ($locationSnap->exists()) {
            $locationData = $locationSnap->data();
            $locationCompanyId = $locationData['companyId'] ?? $locationData['company_id'] ?? null;
            if ($locationCompanyId !== null && (string)$locationCompanyId !== (string)$companyId) {
                http_response_code(403);
                echo json_encode(['error' => 'Login failed: location is not authorized for this account.']);
                exit;
            }
        }
    }
    // ── Sign JWT ─────────────────────────────────────────────────────────────
    $token = jwt_sign([
        'sub'        => $userId,
        'email'      => $email,
        'role'       => $role,
        'company_id' => $companyId,
        'location_id' => $locationId,
        'auth_collection' => $authCollection,
    ], $jwtSecret, 28800); // 8 hours

    echo json_encode([
        'token'                => $token,
        'role'                 => $role,
        'company_id'           => $companyId,
        'location_id'          => $locationId,
        'user'                 => auth_user_payload_for_api($userData, $email),
    ]);

} catch (Exception $e) {
    error_log('[api/auth/login.php] Login exception: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Login failed']);
}
