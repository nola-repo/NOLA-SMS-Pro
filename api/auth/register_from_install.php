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
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/user_profile_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$fullName = trim($input['full_name'] ?? '');
$phone = preg_replace('/\s+/', '', trim($input['phone'] ?? '')); // strip spaces
$email = strtolower(trim($input['email'] ?? ''));
$password = $input['password'] ?? '';
$installToken = $input['install_token'] ?? null; // optional — if present, trust it over raw IDs
$locationId = $input['location_id'] ?? null;
$companyId = $input['company_id'] ?? null;
$payloadLocName = $input['location_name'] ?? null;
$payloadCompName = $input['company_name'] ?? null;

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

// ── Mandate and verify install_token (prevents location bypass) ──
if (!$installToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Install token is required. Please reinstall from the GHL Marketplace.']);
    exit;
}

$installPayload = jwt_verify($installToken, $jwtSecret);
if (!$installPayload) {
    http_response_code(401);
    echo json_encode(['error' => 'Install token is invalid or expired. Please reinstall from the GHL Marketplace.']);
    exit;
}
$type = $installPayload['type'] ?? '';
if ($type === 'install') {
    $locationId = $installPayload['location_id'] ?? $locationId;
    $companyId = $installPayload['company_id'] ?? $companyId;
} elseif ($type === 'agency_install') {
    $companyId = $installPayload['company_id'] ?? $companyId;
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid install token type.']);
    exit;
}

// ── Per-field validation (return all errors at once) ─────────────────────────
$errors = [];
if (!$fullName)
    $errors[] = 'Full name is required.';
if (!$phone)
    $errors[] = 'Phone number is required.';
if (!$email)
    $errors[] = 'Email address is required.';
elseif (!filter_var($email, FILTER_VALIDATE_EMAIL))
    $errors[] = 'A valid email address is required.';
if (!$password)
    $errors[] = 'Password is required.';
elseif (strlen($password) < 8)
    $errors[] = 'Password must be at least 8 characters.';
if (!$locationId && !$companyId)
    $errors[] = 'A location_id or company_id is required.';

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode(['error' => implode(' ', $errors)]);
    exit;
}

try {
    $db = get_firestore();
    $now = new DateTimeImmutable();

    if ($type === 'agency_install' && $locationId && $companyId) {
        if (!auth_location_belongs_to_company($db, (string) $locationId, (string) $companyId)) {
            http_response_code(422);
            echo json_encode(['error' => 'This installation does not match the selected sub-account. Open NOLA SMS Pro from inside the correct GHL sub-account and try again.']);
            exit;
        }
    }

    if ($type === 'agency_install' && !$locationId && $companyId) {
        $inferred = auth_infer_single_location_for_company($db, (string) $companyId);
        if ($inferred) {
            $locationId = $inferred['location_id'];
        }
    }

    $isLocationLevel = !empty($locationId);

    // ── 1. Check if email already exists ─────────────────────────────────────
    $usersRef = $db->collection('users');
    $existingQuery = $usersRef->where('email', '=', $email)->limit(1)->documents();
    $existingDoc = null;
    $existingId = null;

    foreach ($existingQuery as $snap) {
        if ($snap->exists()) {
            $existingDoc = $snap->data();
            $existingId = $snap->id();
            break;
        }
    }

    // ── 1b. Enforce strict 1:1 (subaccount -> email) before creating/linking ─
    $subaccountOwnerId = null;
    $subaccountOwnerEmail = null;
    if ($isLocationLevel) {
        $subaccountOwnerQuery = $usersRef->where('active_location_id', '=', $locationId)->limit(1)->documents();
        foreach ($subaccountOwnerQuery as $snap) {
            if ($snap->exists()) {
                $ownerData = $snap->data();
                $subaccountOwnerId = $snap->id();
                $subaccountOwnerEmail = strtolower(trim((string)($ownerData['email'] ?? '')));
                break;
            }
        }

        if ($subaccountOwnerId !== null && $subaccountOwnerEmail !== '' && $subaccountOwnerEmail !== $email) {
            http_response_code(409);
            echo json_encode(['error' => 'This subaccount is already linked to another email. Please login with the existing account.']);
            exit;
        }
    }

    // ── 1c. If no email match, check for an INCOMPLETE doc by location_id ────
    if (!$existingDoc && $isLocationLevel) {
        $locQuery = $usersRef->where('active_location_id', '=', $locationId)->limit(1)->documents();
        foreach ($locQuery as $snap) {
            if ($snap->exists()) {
                $docData = $snap->data();
                // If the doc is missing core info, we will treat it as "existing" to finish it
                if (empty($docData['email']) || empty($docData['password_hash'])) {
                    $existingDoc = $docData;
                    $existingId = $snap->id();
                }
                break;
            }
        }
    }

    // ── 2a. EXISTING ACCOUNT — link location / complete profile ───────────────
    if ($existingDoc) {
        // Enforce password verification if the account is already fully set up
        if (!empty($existingDoc['email']) && !empty($existingDoc['password_hash'])) {
            if (!password_verify($password, $existingDoc['password_hash'])) {
                http_response_code(401);
                echo json_encode(['error' => 'An account with this email already exists, but the password provided is incorrect.']);
                exit;
            }
        }

        $updates = ['updated_at' => new \Google\Cloud\Core\Timestamp($now)];

        // Try to fetch the real name of the new location from ghl_tokens
        $newLocationName = $payloadLocName;
        $newCompanyName = $payloadCompName;
        if ($locationId) {
            try {
                $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $newLocationName = $tokenData['location_name'] ?? $payloadLocName;
                    $newCompanyName = $tokenData['company_name'] ?? $payloadCompName;
                }
            } catch (Exception $ignored) {
            }
        }

        if ($newLocationName)
            $updates['location_name'] = $newLocationName;
        if ($newCompanyName)
            $updates['company_name'] = $newCompanyName;

        if ($isLocationLevel) {
            $updates['active_location_id'] = $locationId;
        } elseif (!empty($companyId)) {
            $updates['company_id'] = $companyId;
        }

        // If the account was incomplete, populate the missing fields with the form data
        if (empty($existingDoc['email']) || empty($existingDoc['password_hash'])) {
            $updates['email'] = $email;
            $updates['phone'] = $phone;
            $updates['name'] = $fullName;
            $parts = auth_split_full_name($fullName);
            $updates['firstName'] = $parts['firstName'];
            $updates['lastName'] = $parts['lastName'];
            $updates['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }

        $usersRef->document($existingId)->set($updates, ['merge' => true]);

        if ($isLocationLevel && $locationId) {
            // Enforce one-email -> one-subaccount by pruning stale links first.
            _prune_user_subaccounts_except($db, $existingId, $locationId);
            _write_user_subaccount(
                $db,
                $existingId,
                $locationId,
                [
                    'company_id' => $existingDoc['company_id'] ?? $companyId ?? '',
                    'company_name' => $newCompanyName ?? ($existingDoc['company_name'] ?? ''),
                    'location_name' => $newLocationName ?? ($existingDoc['location_name'] ?? ''),
                    'role' => $existingDoc['role'] ?? 'user',
                ],
                $now,
                false
            );
        } elseif (!empty($companyId)) {
            // Agency model follows the same one-email -> one-company link.
            _prune_user_subaccounts_except($db, $existingId, (string)$companyId);
            _write_user_subaccount(
                $db,
                $existingId,
                (string)$companyId,
                [
                    'company_id' => $companyId,
                    'company_name' => $newCompanyName ?? ($existingDoc['company_name'] ?? ''),
                    'role' => $existingDoc['role'] ?? 'agency',
                ],
                $now,
                true
            );
        }

        // Fetch fresh profile for response
        $freshDoc = $db->collection('users')->document($existingId)->snapshot();
        $fd = $freshDoc->exists() ? $freshDoc->data() : array_merge($existingDoc, $updates);

        $role = $fd['role'] ?? $existingDoc['role'] ?? 'user';
        $linkedCo = $fd['company_id'] ?? $companyId ?? null;
        $linkedLoc = $fd['active_location_id'] ?? null;

        $locName = $fd['location_name'] ?? null;
        $compName = $fd['company_name'] ?? null;
        $userApiOut = auth_user_payload_for_api($fd, $email);

        $token = jwt_sign([
            'sub' => $existingId,
            'email' => $email,
            'role' => $role,
            'company_id' => $linkedCo,
        ], $jwtSecret, 28800); // 8 hours

        http_response_code(200);
        echo json_encode([
            'status' => 'linked',
            'message' => 'Account setup complete.',
            'token' => $token,
            'role' => $role,
            'location_id' => $linkedLoc,
            'company_id' => $linkedCo,
            'location_name' => $locName,
            'company_name' => $compName,
            'user' => $userApiOut,
        ]);
        exit;
    }

    // ── 2b. NEW ACCOUNT — create users document ───────────────────────────────
    $role = $isLocationLevel ? 'user' : 'agency';
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $newUserDoc = $usersRef->newDocument();

    // ── Fetch location_name and company_name from ghl_tokens ─────────────────
    $locationName = $payloadLocName;
    $companyName = $payloadCompName;
    if ($locationId) {
        try {
            $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
            if ($tokenSnap->exists()) {
                $tokenData = $tokenSnap->data();
                $locationName = $tokenData['location_name'] ?? $payloadLocName;
                $companyName = $tokenData['company_name'] ?? $payloadCompName;
            }
        } catch (Exception $ignored) {
        }
    }

    $nameParts = auth_split_full_name($fullName);
    $userData = [
        'name' => $fullName,
        'firstName' => $nameParts['firstName'],
        'lastName' => $nameParts['lastName'],
        'email' => $email,
        'phone' => $phone,
        'password_hash' => $passwordHash,
        'role' => $role,
        'active' => true,
        'source' => 'marketplace_install',
        'created_at' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];

    if ($locationName)
        $userData['location_name'] = $locationName;
    if ($companyName)
        $userData['company_name'] = $companyName;

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

    // Write user-owned subaccount entry
    if ($isLocationLevel && $locationId) {
        _write_user_subaccount(
            $db,
            $newUserId,
            $locationId,
            [
                'company_id' => $companyId ?? '',
                'company_name' => $companyName ?? '',
                'location_name' => $locationName ?? '',
                'role' => $role,
            ],
            $now,
            false
        );
    } elseif (!empty($companyId)) {
        _write_user_subaccount(
            $db,
            $newUserId,
            (string)$companyId,
            [
                'company_id' => $companyId,
                'company_name' => $companyName ?? '',
                'role' => $role,
            ],
            $now,
            true
        );
    }

    // Return JWT immediately so the install page can cache auth without a second login
    $token = jwt_sign([
        'sub' => $newUserId,
        'email' => $email,
        'role' => $role,
        'company_id' => $companyId ?? null,
    ], $jwtSecret, 28800);

    $userApiNew = auth_user_payload_for_api($userData, $email);

    http_response_code(201);
    echo json_encode([
        'status' => 'success',
        'message' => 'Account ready.',
        'token' => $token,
        'role' => $role,
        'location_id' => $locationId,
        'company_id' => $companyId ?? null,
        'location_name' => $locationName ?? null,
        'company_name' => $companyName ?? null,
        'user' => $userApiNew,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed: ' . $e->getMessage()]);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Write (or update) a subaccount record in users/{uid}/subaccounts/{entityId}.
 * For agencies, entityId is companyId. For location users, entityId is locationId.
 */
function _write_user_subaccount($db, string $uid, string $entityId, array $details, DateTimeImmutable $now, bool $isAgency = false): void
{
    try {
        $subaccountRef = $db->collection('users')->document($uid)->collection('subaccounts')->document($entityId);
        $payload = [
            'company_id' => $details['company_id'] ?? '',
            'company_name' => $details['company_name'] ?? '',
            'role' => $details['role'] ?? 'user',
            'is_active' => true,
            'linked_at' => new \Google\Cloud\Core\Timestamp($now),
        ];

        if (!$isAgency) {
            $payload['location_id'] = $entityId;
            $payload['location_name'] = $details['location_name'] ?? '';
        }

        $subaccountRef->set($payload, ['merge' => true]);
    } catch (Exception $e) {
        error_log("[register_from_install] _write_user_subaccount failed for uid={$uid}, entity={$entityId}: " . $e->getMessage());
    }
}

/**
 * Keep only the active entity link for a user inside users/{uid}/subaccounts.
 */
function _prune_user_subaccounts_except($db, string $uid, string $keepEntityId): void
{
    try {
        $subs = $db->collection('users')->document($uid)->collection('subaccounts')->documents();
        foreach ($subs as $subDoc) {
            if (!$subDoc->exists()) {
                continue;
            }
            if ((string)$subDoc->id() === (string)$keepEntityId) {
                continue;
            }
            $subDoc->reference()->delete();
        }
    } catch (Exception $e) {
        error_log("[register_from_install] _prune_user_subaccounts_except failed for uid={$uid}, keep={$keepEntityId}: " . $e->getMessage());
    }
}
