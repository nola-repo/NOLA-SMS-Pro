<?php
/**
 * POST /api/auth/register-from-install
 * Handles First-Run Registration from the GHL Marketplace installation callback.
 *
 * Validates all required fields individually and returns a JWT + user profile
 * on both new (201) and existing-email-link (200) paths, so the install page
 * can write to localStorage immediately (no second login call needed).
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$fullName   = trim($input['full_name'] ?? '');
$phone      = trim($input['phone']     ?? '');
$email      = strtolower(trim($input['email'] ?? ''));
$password   = $input['password']    ?? '';
$locationId = $input['location_id'] ?? null;
$companyId  = $input['company_id']  ?? null;

// ── Per-field validation (return all errors at once) ─────────────────────────
$errors = [];
if (!$fullName)  $errors[] = 'Full name is required.';
if (!$phone)     $errors[] = 'Phone number is required.';
if (!$email)     $errors[] = 'Email address is required.';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email address is required.';
if (!$password)  $errors[] = 'Password is required.';
elseif (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
if (!$locationId && !$companyId) $errors[] = 'A location_id or company_id is required.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

// Split full name → firstName + lastName
$nameParts = explode(' ', $fullName, 2);
$firstName = $nameParts[0];
$lastName  = $nameParts[1] ?? '';

$jwtSecret       = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$isLocationLevel = !empty($locationId);

try {
    $db  = get_firestore();
    $now = new DateTimeImmutable();

    // ── 1. Check if email already exists ─────────────────────────────────────
    $usersRef      = $db->collection('users');
    $existingQuery = $usersRef->where('email', '=', $email)->limit(1)->documents();
    $existingDoc   = null;
    $existingId    = null;

    foreach ($existingQuery as $snap) {
        if ($snap->exists()) {
            $existingDoc = $snap->data();
            $existingId  = $snap->id();
            break;
        }
    }

    // ── 1b. If no email match, check for an INCOMPLETE doc by location_id ────
    if (!$existingDoc && $isLocationLevel) {
        $locQuery = $usersRef->where('active_location_id', '=', $locationId)->limit(1)->documents();
        foreach ($locQuery as $snap) {
            if ($snap->exists()) {
                $docData = $snap->data();
                // If the doc is missing core info, we will treat it as "existing" to finish it
                if (empty($docData['email']) || empty($docData['password_hash'])) {
                    $existingDoc = $docData;
                    $existingId  = $snap->id();
                }
                break;
            }
        }
    }

    // ── 2a. EXISTING ACCOUNT — link location / complete profile ───────────────
    if ($existingDoc) {
        $updates = ['updated_at' => new \Google\Cloud\Core\Timestamp($now)];

        if ($isLocationLevel) {
            $updates['active_location_id'] = $locationId;
        } elseif (!empty($companyId)) {
            $updates['company_id'] = $companyId;
        }

        // If the account was incomplete, populate the missing fields with the form data
        if (empty($existingDoc['email']) || empty($existingDoc['password_hash'])) {
            $updates['email']         = $email;
            $updates['phone']         = $phone;
            $updates['firstName']     = $firstName;
            $updates['lastName']      = $lastName;
            $updates['name']          = $fullName;
            $updates['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $usersRef->document($existingId)->set($updates, ['merge' => true]);

        // Sync integration record & GHL Custom Values
        update_integration_record($db, $locationId, $email, $fullName, $phone, $now);
        _sync_owner_to_ghl($db, $locationId, $fullName, $email, $phone);

        $role      = $existingDoc['role']       ?? 'user';
        $linkedCo  = $existingDoc['company_id'] ?? $companyId ?? null;
        $linkedLoc = $isLocationLevel ? $locationId : ($existingDoc['active_location_id'] ?? null);

        $token = jwt_sign([
            'sub'        => $existingId,
            'email'      => $email,
            'role'       => $role,
            'company_id' => $linkedCo,
        ], $jwtSecret, 28800); // 8 hours

        http_response_code(200);
        echo json_encode([
            'status'      => 'linked',
            'message'     => 'Account setup complete.',
            'token'       => $token,
            'role'        => $role,
            'location_id' => $linkedLoc,
            'company_id'  => $linkedCo,
            'user' => [
                'firstName'   => $updates['firstName'] ?? $existingDoc['firstName'] ?? $firstName,
                'lastName'    => $updates['lastName']  ?? $existingDoc['lastName']  ?? $lastName,
                'email'       => $email,
                'phone'       => $updates['phone']     ?? $existingDoc['phone']     ?? $phone,
                'location_id' => $linkedLoc,
            ],
        ]);
        exit;
    }

    // ── 2b. NEW ACCOUNT — create users document ───────────────────────────────
    $role         = $isLocationLevel ? 'user' : 'agency';
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $newUserDoc   = $usersRef->newDocument();

    $userData = [
        'firstName'     => $firstName,
        'lastName'      => $lastName,
        'name'          => $fullName,    // legacy full-name field for older code paths
        'email'         => $email,
        'phone'         => $phone,
        'password_hash' => $passwordHash,
        'role'          => $role,
        'active'        => true,
        'source'        => 'marketplace_install',
        'created_at'    => new \Google\Cloud\Core\Timestamp($now),
        'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
    ];

    if ($isLocationLevel) {
        $userData['active_location_id'] = $locationId;
    }
    if (!empty($companyId)) {
        $userData['company_id'] = $companyId;
        if ($role === 'agency') {
            $userData['agency_id'] = $companyId; // backwards compat
        }
    }

    $newUserDoc->set($userData);
    $newUserId = $newUserDoc->id();

    // Sync integration record & GHL Custom Values
    update_integration_record($db, $locationId, $email, $fullName, $phone, $now);
    _sync_owner_to_ghl($db, $locationId, $fullName, $email, $phone);

    // Return JWT immediately so the install page can cache auth without a second login
    $token = jwt_sign([
        'sub'        => $newUserId,
        'email'      => $email,
        'role'       => $role,
        'company_id' => $companyId ?? null,
    ], $jwtSecret, 28800);

    http_response_code(201);
    echo json_encode([
        'status'      => 'success',
        'message'     => 'Account ready.',
        'token'       => $token,
        'role'        => $role,
        'location_id' => $locationId,
        'company_id'  => $companyId ?? null,
        'user' => [
            'firstName'   => $firstName,
            'lastName'    => $lastName,
            'email'       => $email,
            'phone'       => $phone,
            'location_id' => $locationId,
        ],
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Write owner contact info into the integrations/<intDocId> document.
 */
function update_integration_record($db, ?string $locationId, string $email, string $fullName, string $phone, DateTimeImmutable $now): void
{
    if (!$locationId) return;

    $intDocId       = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    $integrationRef = $db->collection('integrations')->document($intDocId);

    if ($integrationRef->snapshot()->exists()) {
        $integrationRef->set([
            'owner_email' => $email,
            'owner_name'  => $fullName,
            'owner_phone' => $phone,
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now),
        ], ['merge' => true]);
    }
}

/**
 * Write owner_name / owner_email / owner_phone to GHL Location Custom Values.
 * Creates the custom field definitions if they don't already exist.
 * Errors are logged but never bubble up — this is best-effort.
 */
function _sync_owner_to_ghl($db, ?string $locationId, string $fullName, string $email, string $phone): void
{
    if (!$locationId) return;

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
    try {
        $intSnap     = $db->collection('integrations')->document($intDocId)->snapshot();
        if (!$intSnap->exists()) return;
        $accessToken = $intSnap->data()['access_token'] ?? null;
        if (!$accessToken) return;
    } catch (Exception $e) {
        error_log("[register_from_install] _sync_owner_to_ghl: fetch integration failed for $locationId: " . $e->getMessage());
        return;
    }

    $headers = [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: application/json',
        'Accept: application/json',
        'Version: 2021-07-28',
    ];

    $fields = [
        'owner_name'  => $fullName,
        'owner_email' => $email,
        'owner_phone' => $phone,
    ];

    foreach ($fields as $fieldKey => $value) {
        try {
            $fieldId = _ghl_get_or_create_custom_field($locationId, $fieldKey, $headers);
            if (!$fieldId) continue;

            $ch = curl_init("https://services.leadconnectorhq.com/locations/{$locationId}/customValues/{$fieldId}");
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => 'PUT',
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_POSTFIELDS     => json_encode(['value' => $value]),
            ]);
            curl_exec($ch);
            curl_close($ch);
        } catch (Exception $e) {
            error_log("[register_from_install] _sync_owner_to_ghl: failed to set $fieldKey for $locationId: " . $e->getMessage());
        }
    }
}

/**
 * Return the GHL custom field ID for $fieldKey on $locationId.
 * Creates the field (name, fieldKey, dataType: TEXT) if it doesn't exist.
 */
function _ghl_get_or_create_custom_field(string $locationId, string $fieldKey, array $headers): ?string
{
    // 1. Look for existing field
    $ch = curl_init("https://services.leadconnectorhq.com/locations/{$locationId}/customFields");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200) {
        $data = json_decode($resp, true);
        foreach (($data['customFields'] ?? []) as $f) {
            if (($f['fieldKey'] ?? '') === $fieldKey) {
                return $f['id'];
            }
        }
    }

    // 2. Create the field
    $nameMap = [
        'owner_name'  => 'Owner Name',
        'owner_email' => 'Owner Email',
        'owner_phone' => 'Owner Phone',
    ];
    $ch = curl_init("https://services.leadconnectorhq.com/locations/{$locationId}/customFields");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POSTFIELDS     => json_encode([
            'name'     => $nameMap[$fieldKey] ?? $fieldKey,
            'fieldKey' => $fieldKey,
            'dataType' => 'TEXT',
        ]),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 || $code === 201) {
        $data = json_decode($resp, true);
        return $data['customField']['id'] ?? $data['id'] ?? null;
    }

    return null;
}
