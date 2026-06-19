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
$rawEmail = trim((string)($input['email'] ?? ''));
$email    = strtolower($rawEmail);
$password = $input['password'] ?? '';
$rememberMe = !empty($input['remember_me']) || !empty($input['rememberMe']);

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

function login_find_user_by_email($db, string $email, string $rawEmail): array
{
    $candidates = array_values(array_unique(array_filter([
        strtolower(trim($email)),
        trim($rawEmail),
    ], fn($value) => $value !== '')));

    foreach (['agency_users', 'users'] as $collection) {
        foreach ($candidates as $candidate) {
            $results = $db->collection($collection)
                ->where('email', '=', $candidate)
                ->limit(1)
                ->documents();

            foreach ($results as $doc) {
                if ($doc->exists()) {
                    return [$doc->id(), $doc->data(), $collection];
                }
            }
        }
    }

    // Firestore equality is case-sensitive; use this only after indexed lookups fail.
    foreach (['agency_users', 'users'] as $collection) {
        $docs = $db->collection($collection)->documents();
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $data = $doc->data();
            $storedEmail = $data['email'] ?? $data['email_address'] ?? $data['username'] ?? '';
            if (strtolower(trim((string)$storedEmail)) === $email || strtolower((string)$doc->id()) === $email) {
                return [$doc->id(), $data, $collection];
            }
        }
    }

    return [null, null, null];
}

function login_stored_password_hash(array $userData): string
{
    foreach (['password_hash', 'hashed_password', 'passwordHash', 'hashedPassword'] as $field) {
        $value = trim((string)($userData[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}
try {
    $db = get_firestore();

    // ── Prefer agency_users for agency accounts, fallback to users ───────────
    [$userId, $userData, $authCollection] = login_find_user_by_email($db, $email, $rawEmail);


    if (!$userData) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    // ── Verify password ──────────────────────────────────────────────────────
    $storedHash = login_stored_password_hash($userData);
    if ($storedHash === '' || !password_verify($password, $storedHash)) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid email or password.']);
        exit;
    }

    if (array_key_exists('active', $userData) && empty($userData['active'])) {
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
    $tokenTtl = $rememberMe ? 60 * 60 * 24 * 30 : 28800;
    $token = jwt_sign([
        'sub'        => $userId,
        'email'      => $email,
        'role'       => $role,
        'company_id' => $companyId,
        'location_id' => $locationId,
        'auth_collection' => $authCollection,
    ], $jwtSecret, $tokenTtl);

    echo json_encode([
        'token'                => $token,
        'role'                 => $role,
        'company_id'           => $companyId,
        'location_id'          => $locationId,
        'user'                 => auth_user_payload_for_api($userData, $email),
        'expires_in'           => $tokenTtl,
        'remembered'           => $rememberMe,
    ]);

} catch (Exception $e) {
    error_log('[api/auth/login.php] Login exception: ' . $e->getMessage() . '\n' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Login failed']);
}
