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
require_once __DIR__ . '/../install_helpers.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/user_profile_helper.php';
require_once __DIR__ . '/../services/LocationUserResolver.php';

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
$ghlUserId = trim((string)($input['ghl_user_id'] ?? $input['ghlUserId'] ?? ''));

$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    http_response_code(500);
    echo json_encode(['error' => 'Server misconfiguration: JWT secret missing.']);
    exit;
}

// ── Mandate and verify install_token (prevents location bypass) ──
if (!$installToken) {
    http_response_code(401);
    echo json_encode(['error' => 'Install token is required. Please reinstall from the GHL Marketplace.']);
    exit;
}

$installVerification = jwt_verify_detailed($installToken, $jwtSecret);
$installPayload = $installVerification['payload'];
if (!$installVerification['valid']) {
    http_response_code(401);
    $expired = $installVerification['reason'] === 'expired';
    echo json_encode([
        'error' => $expired
            ? 'Install token expired. Please restart installation from the GHL Marketplace.'
            : 'Install token is invalid. Please restart installation from the GHL Marketplace.',
        'code' => $expired ? 'INSTALL_TOKEN_EXPIRED' : 'INSTALL_TOKEN_INVALID',
        'next_action' => 'restart_marketplace_install',
    ]);
    exit;
}
if ($ghlUserId === '') {
    $ghlUserId = trim((string)($installPayload['ghl_user_id'] ?? $installPayload['ghlUserId'] ?? ''));
}
$type = $installPayload['type'] ?? '';
if ($type === 'install') {
    $locationId = $installPayload['location_id'] ?? $locationId;
    $companyId = $installPayload['company_id'] ?? $companyId;
    if (!$locationId) {
        http_response_code(422);
        echo json_encode(['error' => 'A reliable location_id is required for sub-account installation. Please reinstall from the selected GHL sub-account.']);
        exit;
    }
} elseif ($type === 'agency_install') {
    $companyId = $installPayload['company_id'] ?? $companyId;
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid install token type.']);
    exit;
}

if ($type === 'agency_install') {
    $agencyErrors = [];
    if (!$fullName) {
        $agencyErrors[] = 'Full name is required.';
    }
    if (!$phone) {
        $agencyErrors[] = 'Phone number is required.';
    }
    if (!$email) {
        $agencyErrors[] = 'Email address is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $agencyErrors[] = 'A valid email address is required.';
    }
    if (!$password) {
        $agencyErrors[] = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $agencyErrors[] = 'Password must be at least 8 characters.';
    }
    if (!$companyId) {
        $agencyErrors[] = 'A company_id is required for agency install.';
    }

    if (!empty($agencyErrors)) {
        http_response_code(422);
        echo json_encode(['error' => implode(' ', $agencyErrors)]);
        exit;
    }

    try {
        $db = get_firestore();
        $now = new DateTimeImmutable();
        $agencyUsersRef = $db->collection('agency_users');

        $companyName = $payloadCompName ?: ($installPayload['company_name'] ?? null);
        if ($companyId && !$companyName) {
            try {
                $companySnap = $db->collection('ghl_tokens')->document((string)$companyId)->snapshot();
                if ($companySnap->exists()) {
                    $companyData = $companySnap->data();
                    $companyName = install_extract_company_name($companyData)
                        ?: (trim((string)($companyData['location_name'] ?? '')) ?: $companyName);
                }
            } catch (Exception $ignored) {
            }
        }
        install_upsert_agency_registry(
            $db,
            (string)$companyId,
            trim((string)$companyName),
            'agency_registration',
            $now
        );

        $existingQuery = $agencyUsersRef->where('email', '=', $email)->limit(1)->documents();
        $existingDoc = null;
        $existingId = null;
        foreach ($existingQuery as $snap) {
            if ($snap->exists()) {
                $existingDoc = $snap->data();
                $existingId = $snap->id();
                break;
            }
        }

        // Temporary migration fallback: legacy agency accounts in `users`.
        if (!$existingDoc) {
            $legacyQuery = $db->collection('users')
                ->where('email', '=', $email)
                ->where('role', '=', 'agency')
                ->limit(1)
                ->documents();
            foreach ($legacyQuery as $legacySnap) {
                if (!$legacySnap->exists()) {
                    continue;
                }
                $legacyData = $legacySnap->data();
                $migratedRef = $agencyUsersRef->newDocument();
                $migratedPayload = [
                    'name' => $legacyData['name'] ?? '',
                    'firstName' => $legacyData['firstName'] ?? '',
                    'lastName' => $legacyData['lastName'] ?? '',
                    'email' => strtolower(trim((string)($legacyData['email'] ?? $email))),
                    'phone' => $legacyData['phone'] ?? '',
                    'password_hash' => $legacyData['password_hash'] ?? '',
                    'role' => 'agency',
                    'active' => isset($legacyData['active']) ? (bool)$legacyData['active'] : true,
                    'source' => $legacyData['source'] ?? 'migrated_from_users',
                    'company_id' => $legacyData['company_id'] ?? $companyId,
                    'created_at' => $legacyData['created_at'] ?? new \Google\Cloud\Core\Timestamp($now),
                    'updated_at' => new \Google\Cloud\Core\Timestamp($now),
                    'legacy_user_id' => $legacySnap->id(),
                    'migrated_from_users' => true,
                ];
                $migratedCompanyName = trim((string)($legacyData['company_name'] ?? $companyName ?? ''));
                if ($migratedCompanyName !== '') {
                    $migratedPayload['company_name'] = $migratedCompanyName;
                }
                $migratedRef->set($migratedPayload, ['merge' => true]);

                $existingDoc = $migratedRef->snapshot()->data();
                $existingId = $migratedRef->id();
                break;
            }
        }

        if ($companyId && !_owner_lock_available_for_email($db, 'company_owners', (string)$companyId, $email)) {
            http_response_code(409);
            echo json_encode(['error' => 'This agency company is already linked to another email. Please login with the existing account.']);
            exit;
        }

        if (!$existingDoc && $companyId) {
            $existingCompanyQuery = $agencyUsersRef->where('company_id', '=', (string)$companyId)->limit(1)->documents();
            foreach ($existingCompanyQuery as $snap) {
                if ($snap->exists()) {
                    $companyDoc = $snap->data();
                    $companyEmail = strtolower(trim((string)($companyDoc['email'] ?? '')));
                    if ($companyEmail !== '' && $companyEmail !== $email) {
                        http_response_code(409);
                        echo json_encode(['error' => 'This agency company is already linked to another email. Please login with the existing account.']);
                        exit;
                    }
                    $existingDoc = $companyDoc;
                    $existingId = $snap->id();
                    break;
                }
            }
        }

        $parts = auth_split_full_name($fullName);
        if ($existingDoc) {
            if (!empty($existingDoc['email']) && !empty($existingDoc['password_hash'])) {
                if (!password_verify($password, (string)$existingDoc['password_hash'])) {
                    http_response_code(401);
                    echo json_encode(['error' => 'An account with this email already exists, but the password provided is incorrect.']);
                    exit;
                }
            }

            $updates = [
                'updated_at' => new \Google\Cloud\Core\Timestamp($now),
                'role' => 'agency',
                'active' => true,
                'email' => $email,
                'phone' => $phone,
                'name' => $fullName,
                'firstName' => $parts['firstName'],
                'lastName' => $parts['lastName'],
                'company_id' => $companyId,
            ];
            if (trim((string)$companyName) !== '') {
                $updates['company_name'] = trim((string)$companyName);
            }
            if (empty($existingDoc['password_hash'])) {
                $updates['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $agencyUsersRef->document($existingId)->set($updates, ['merge' => true]);
            $fresh = $agencyUsersRef->document($existingId)->snapshot();
            $fd = $fresh->exists() ? $fresh->data() : array_merge($existingDoc, $updates);

            $token = jwt_sign([
                'sub' => $existingId,
                'email' => $email,
                'role' => 'agency',
                'company_id' => $companyId ?? null,
                'auth_collection' => 'agency_users',
            ], $jwtSecret, 28800);
            if ($companyId) {
                _upsert_owner_lock($db, 'company_owners', (string)$companyId, $existingId, $email, $fullName, $now);
            }

            http_response_code(200);
            echo json_encode([
                'status' => 'linked',
                'message' => 'Agency account setup complete.',
                'token' => $token,
                'role' => 'agency',
                'location_id' => null,
                'company_id' => $companyId ?? null,
                'location_name' => null,
                'company_name' => $companyName ?? null,
                'user' => auth_user_payload_for_api($fd, $email),
            ]);
            exit;
        }

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $newAgencyDoc = $agencyUsersRef->newDocument();
        $agencyData = [
            'name' => $fullName,
            'firstName' => $parts['firstName'],
            'lastName' => $parts['lastName'],
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $passwordHash,
            'role' => 'agency',
            'active' => true,
            'source' => 'marketplace_install',
            'company_id' => $companyId,
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ];
        if (trim((string)$companyName) !== '') {
            $agencyData['company_name'] = trim((string)$companyName);
        }
        $newAgencyDoc->set($agencyData);
        $newAgencyId = $newAgencyDoc->id();
        if ($companyId) {
            _upsert_owner_lock($db, 'company_owners', (string)$companyId, $newAgencyId, $email, $fullName, $now);
        }

        $token = jwt_sign([
            'sub' => $newAgencyId,
            'email' => $email,
            'role' => 'agency',
            'company_id' => $companyId ?? null,
            'auth_collection' => 'agency_users',
        ], $jwtSecret, 28800);

        http_response_code(201);
        echo json_encode([
            'status' => 'success',
            'message' => 'Agency account ready.',
            'token' => $token,
            'role' => 'agency',
            'location_id' => null,
            'company_id' => $companyId ?? null,
            'location_name' => null,
            'company_name' => $companyName ?? null,
            'user' => auth_user_payload_for_api($agencyData, $email),
        ]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Registration failed.']);
        exit;
    }
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
    $rflogId = bin2hex(random_bytes(4));
    $rflogStart = microtime(true);
    $db = get_firestore();
    $now = new DateTimeImmutable();
    register_from_install_log_timing($rflogId, 'init', $rflogStart);

    if ($type === 'install' && $locationId && $companyId && install_location_company_mismatch($db, (string)$locationId, (string)$companyId)) {
        http_response_code(422);
        echo json_encode(['error' => 'This installation does not match the selected sub-account. Open NOLA SMS Pro from inside the correct GHL sub-account and try again.']);
        exit;
    }

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
    if ($isLocationLevel && $locationId) {
        $activationCheck = install_registration_activation_check($db, (string)$locationId);
        if (empty($activationCheck['ok'])) {
            $activationCode = (string)($activationCheck['code'] ?? 'LOCATION_INSTALL_PENDING');
            $activationHttp = $activationCode === 'LOCATION_NOT_INSTALLED' ? 404
                : ($activationCode === 'LOCATION_CLEANUP_IN_PROGRESS' ? 423 : 409);
            http_response_code($activationHttp);
            echo json_encode([
                'error' => $activationCheck['message'] ?? 'Installation is not ready for registration.',
                'code' => $activationCode,
                'next_action' => $activationCode === 'LOCATION_NOT_INSTALLED'
                    ? 'restart_marketplace_install'
                    : 'retry_installation_status',
                'location_id' => $locationId,
            ]);
            exit;
        }
    }
    register_from_install_log_timing($rflogId, 'guards', $rflogStart);

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

    // Linked GHL sub-accounts are handled by the login-only reinstall flow.

    // ── 1c. If no email match, enforce one account per location_id ────
    if (!$existingDoc && $isLocationLevel) {
        $locationUser = register_from_install_find_user_for_location($db, (string)$locationId);
        if ($locationUser !== null) {
            $docData = $locationUser['data'];
            // If the doc is missing core info, treat it as "existing" to finish it.
            // A complete active user for this location means the subaccount is already registered.
            if (register_from_install_user_is_incomplete($docData)) {
                $existingDoc = $docData;
                $existingId = $locationUser['id'];
            } else {
                register_from_install_location_conflict((string)$locationId);
            }
        }
    }

    $ownershipMode = 'none';
    register_from_install_log_timing($rflogId, 'email_lookup', $rflogStart);

    // A GHL location may have exactly one NOLA user. Reinstalling with the
    // canonical account is allowed; registering a second account is not.
    if ($isLocationLevel && $locationId) {
        if (is_array($existingDoc)) {
            foreach (install_user_location_ids($existingDoc) as $existingLocationId) {
                if ($existingLocationId !== (string)$locationId) {
                    register_from_install_account_location_conflict((string)$locationId);
                }
            }
        }

        if ($existingId !== null) {
            foreach ($db->collection('location_owners')->where('owner_user_id', '=', (string)$existingId)->limit(2)->documents() as $ownedLocation) {
                if ($ownedLocation->exists() && $ownedLocation->id() !== (string)$locationId) {
                    register_from_install_account_location_conflict((string)$locationId);
                }
            }
        }

        $otherLocationUser = register_from_install_find_user_for_location(
            $db,
            (string)$locationId,
            $existingId !== null ? (string)$existingId : null,
            true
        );
        if ($otherLocationUser !== null) {
            register_from_install_location_conflict((string)$locationId);
        }

        $canonicalAccount = install_linked_account_for_location($db, (string)$locationId, false);
        $canonicalUserId = trim((string)($canonicalAccount['id'] ?? ''));
        if ($canonicalUserId !== '' && $canonicalUserId !== (string)$existingId) {
            register_from_install_location_conflict((string)$locationId);
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

        if ($isLocationLevel && $locationId) {
            $ownershipMode = install_attach_user_to_location_ownership($db, (string)$locationId, $existingId, $email, $fullName, $phone, $now, 'register_from_install_existing');
            if ($ownershipMode !== 'primary') {
                register_from_install_location_conflict((string)$locationId);
            }
            register_from_install_log_timing($rflogId, 'owner_attach', $rflogStart);
        }

        $updates = [
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
            'activation_state' => 'pending',
        ];

        // Try to fetch the real name of the new location from ghl_tokens
        $newLocationName = $payloadLocName;
        $newCompanyName = $payloadCompName;
        if ($locationId) {
            try {
                $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $newLocationName = $tokenData['location_name'] ?? $payloadLocName;
                    $newCompanyName = install_extract_company_name($tokenData) ?: $payloadCompName;
                }
            } catch (Exception $ignored) {
            }
        }
        if (!$newCompanyName && $companyId) {
            try {
                $companySnap = $db->collection('ghl_tokens')->document((string)$companyId)->snapshot();
                if ($companySnap->exists()) {
                    $companyData = $companySnap->data();
                    $newCompanyName = install_extract_company_name($companyData)
                        ?: (trim((string)($companyData['location_name'] ?? '')) ?: $newCompanyName);
                }
            } catch (Exception $ignored) {
            }
        }
        install_upsert_agency_registry(
            $db,
            (string)$companyId,
            trim((string)$newCompanyName),
            'location_registration',
            $now
        );

        if ($newLocationName)
            $updates['location_name'] = $newLocationName;
        if ($newCompanyName)
            $updates['company_name'] = $newCompanyName;

        if ($isLocationLevel) {
            $updates['active_location_id'] = $locationId;
            $updates['location_id'] = $locationId;
            $updates['ghl_token_ref'] = 'ghl_tokens/' . $locationId;
            if (!array_key_exists('credit_balance', $existingDoc)) {
                $updates['credit_balance'] = users_resolve_initial_credit_balance_for_location($db, (string)$locationId);
            }
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
        register_from_install_log_timing($rflogId, 'user_write', $rflogStart);

        if ($isLocationLevel && $locationId) {
            // Enforce one-email -> one-subaccount by pruning stale links first.
            _prune_user_subaccounts_except($db, $existingId, $locationId);
            _write_user_subaccount(
                $db,
                $existingId,
                $locationId,
                [
                    'company_id' => $companyId ?? ($existingDoc['company_id'] ?? ''),
                    'company_name' => $newCompanyName ?? ($existingDoc['company_name'] ?? ''),
                    'location_name' => $newLocationName ?? ($existingDoc['location_name'] ?? ''),
                    'role' => $existingDoc['role'] ?? 'user',
                ],
                $now,
                false,
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

        if ($isLocationLevel && $locationId) {
            install_finalize_registered_location_fast($db, (string)$locationId, [
                'owner_user_id' => $existingId,
                'owner_email' => $email,
                'owner_name' => $fullName,
                'owner_phone' => $phone,
            ], $now);
            (new CreditManager())->ensureSubaccountCreditBalanceForLocation((string)$locationId);
            register_from_install_log_timing($rflogId, 'activation_commit', $rflogStart);
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
            'location_id' => $linkedLoc,
            'auth_collection' => 'users',
        ], $jwtSecret, 28800); // 8 hours
        register_from_install_log_timing($rflogId, 'jwt_sign', $rflogStart);

        register_from_install_log_timing($rflogId, 'before_response', $rflogStart);
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

        if ($isLocationLevel && $locationId) {
            $ownerContext = $ownershipMode === 'primary'
                ? [
                    'owner_user_id' => $existingId,
                    'owner_email' => $email,
                    'owner_name' => $fullName,
                    'owner_phone' => $phone,
                ]
                : [];
            register_from_install_run_deferred_finalize(
                $db,
                (string) $locationId,
                $ownerContext,
                $email,
                $fullName,
                $phone,
                $role,
                $now,
                $rflogId,
                $rflogStart
            );
        } else {
            register_from_install_log_timing($rflogId, 'total', $rflogStart);
        }
        exit;
    }

    // ── 2b. NEW ACCOUNT — create users document ───────────────────────────────
    $role = $isLocationLevel ? 'user' : 'agency';
    $passwordHash = password_hash($password, PASSWORD_BCRYPT);
    $newUserDoc = $usersRef->newDocument();
    $newUserId = $newUserDoc->id();

    // ── Fetch location_name and company_name from ghl_tokens ─────────────────
    $locationName = $payloadLocName;
    $companyName = $payloadCompName;
    if ($locationId) {
        try {
            $tokenSnap = $db->collection('ghl_tokens')->document($locationId)->snapshot();
            if ($tokenSnap->exists()) {
                $tokenData = $tokenSnap->data();
                $locationName = $tokenData['location_name'] ?? $payloadLocName;
                $companyName = install_extract_company_name($tokenData) ?: $payloadCompName;
            }
        } catch (Exception $ignored) {
        }
    }
    if (!$companyName && $companyId) {
        try {
            $companySnap = $db->collection('ghl_tokens')->document((string)$companyId)->snapshot();
            if ($companySnap->exists()) {
                $companyData = $companySnap->data();
                $companyName = install_extract_company_name($companyData)
                    ?: (trim((string)($companyData['location_name'] ?? '')) ?: $companyName);
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
        'active' => false,
        'activation_state' => 'pending',
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
        $userData['location_id'] = $locationId;
        $userData['ghl_token_ref'] = 'ghl_tokens/' . $locationId;
        $userData['credit_balance'] = users_resolve_initial_credit_balance_for_location($db, (string)$locationId);
    }
    if (!empty($companyId)) {
        $userData['company_id'] = $companyId;
        if ($role === 'agency') {
            $userData['agency_id'] = $companyId; // backwards compat
        }
    }

    // Persist a non-usable pending account first. The activation batch below
    // promotes the user, owner, token, integration, and subaccount together.
    $newUserDoc->set($userData);
    register_from_install_log_timing($rflogId, 'pending_user_write', $rflogStart);

    if ($isLocationLevel && $locationId) {
        $ownershipMode = install_attach_user_to_location_ownership($db, (string)$locationId, $newUserId, $email, $fullName, $phone, $now, 'register_from_install_new');
        if ($ownershipMode !== 'primary') {
            register_from_install_location_conflict((string)$locationId);
        }
        register_from_install_log_timing($rflogId, 'owner_attach', $rflogStart);
    }

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
            false,
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
    register_from_install_log_timing($rflogId, 'subaccount_write', $rflogStart);

    if ($isLocationLevel && $locationId) {
        install_finalize_registered_location_fast($db, (string)$locationId, [
            'owner_user_id' => $newUserId,
            'owner_email' => $email,
            'owner_name' => $fullName,
            'owner_phone' => $phone,
        ], $now);
        (new CreditManager())->ensureSubaccountCreditBalanceForLocation((string)$locationId);
        $freshUser = $newUserDoc->snapshot();
        if ($freshUser->exists()) {
            $userData = $freshUser->data();
        }
        register_from_install_log_timing($rflogId, 'activation_commit', $rflogStart);
    }

    // JWT is issued only after synchronous activation succeeds.
    $token = jwt_sign([
        'sub' => $newUserId,
        'email' => $email,
        'role' => $role,
        'company_id' => $companyId ?? null,
        'location_id' => $locationId ?? null,
        'auth_collection' => 'users',
    ], $jwtSecret, 28800);

    $userApiNew = auth_user_payload_for_api($userData, $email);
    register_from_install_log_timing($rflogId, 'jwt_sign', $rflogStart);

    register_from_install_log_timing($rflogId, 'before_response', $rflogStart);
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

    if ($isLocationLevel && $locationId) {
        $ownerContext = $ownershipMode === 'primary'
            ? [
                'owner_user_id' => $newUserId,
                'owner_email' => $email,
                'owner_name' => $fullName,
                'owner_phone' => $phone,
            ]
            : [];
        register_from_install_run_deferred_finalize(
            $db,
            (string) $locationId,
            $ownerContext,
            $email,
            $fullName,
            $phone,
            $role,
            $now,
            $rflogId,
            $rflogStart
        );
    } else {
        register_from_install_log_timing($rflogId, 'total', $rflogStart);
    }

} catch (Exception $e) {
    error_log("[api/auth/register_from_install.php] Registration exception: {$e->getMessage()}\n{$e->getTraceAsString()}");
    http_response_code(500);
    echo json_encode(['error' => 'Registration failed.']);
}

// ── Helpers ───────────────────────────────────────────────────────────────────

function register_from_install_log_timing(string $rid, string $step, float $sinceStart): void
{
    error_log('[register_from_install] rid=' . $rid . ' step=' . $step . ' ms=' . (int) round((microtime(true) - $sinceStart) * 1000));
}

function register_from_install_location_conflict(string $locationId): void
{
    $loginUrl = 'https://smspro-api.nolacrm.io/login?welcome_back=1'
        . '&location_id=' . urlencode($locationId)
        . '&install_status=' . urlencode(INSTALL_STATE_LINKED_ACCOUNT)
        . '&resolution_source=' . urlencode('register_conflict_existing_owner');
    http_response_code(409);
    echo json_encode([
        'error' => 'This GHL location already has a NOLA SMS Pro account. Sign in with the existing account or contact support to transfer ownership.',
        'code' => 'LOCATION_ALREADY_REGISTERED',
        'location_id' => $locationId,
        'next_action' => 'login',
        'login_url' => $loginUrl,
    ]);
    exit;
}

function register_from_install_account_location_conflict(string $locationId): void
{
    http_response_code(409);
    echo json_encode([
        'error' => 'This NOLA SMS Pro account is already linked to another GHL location. Use the account assigned to this location or contact support.',
        'code' => 'ACCOUNT_ALREADY_LINKED_TO_LOCATION',
        'location_id' => $locationId,
    ]);
    exit;
}

function register_from_install_user_is_incomplete(array $userData): bool
{
    return empty($userData['email']) || empty($userData['password_hash']);
}

/**
 * Find an active non-agency user that already points at this GHL location.
 *
 * This protects the legacy path where users.active_location_id exists but
 * location_owners/{locationId} has not been backfilled yet. Incomplete
 * placeholders are returned so registration can finish them; complete users
 * are treated as an existing account for the subaccount.
 *
 * @return array{id:string,data:array}|null
 */
function register_from_install_find_user_for_location(
    $db,
    string $locationId,
    ?string $excludeUserId = null,
    bool $completeOnly = false
): ?array {
    $locationId = trim($locationId);
    if ($locationId === '') {
        return null;
    }

    $queries = [
        ['active_location_id', $locationId],
        ['location_id', $locationId],
        ['ghl_location_id', $locationId],
        ['ghl_token_ref', 'ghl_tokens/' . $locationId],
    ];
    $seen = [];

    foreach ($queries as [$field, $value]) {
        try {
            foreach ($db->collection('users')->where($field, '=', $value)->limit(10)->documents() as $doc) {
                if (!$doc->exists()) {
                    continue;
                }

                $id = trim((string)$doc->id());
                if ($id === '' || isset($seen[$id]) || ($excludeUserId !== null && $id === $excludeUserId)) {
                    continue;
                }
                $seen[$id] = true;

                $data = $doc->data();
                if (!is_array($data) || !install_is_active_user($data)) {
                    continue;
                }

                $role = strtolower(trim((string)($data['role'] ?? 'user')));
                if ($role === 'agency') {
                    continue;
                }

                if (!in_array($locationId, install_user_location_ids($data), true)) {
                    continue;
                }

                if ($completeOnly && register_from_install_user_is_incomplete($data)) {
                    continue;
                }

                return ['id' => $id, 'data' => $data];
            }
        } catch (Exception $e) {
            error_log("[register_from_install] user/location uniqueness lookup failed field={$field} location={$locationId}: " . $e->getMessage());
        }
    }

    return null;
}

/**
 * @param array{owner_user_id?:string,owner_email?:string,owner_name?:string,owner_phone?:string} $ownerContext
 */
function register_from_install_run_deferred_finalize(
    $db,
    string $locationId,
    array $ownerContext,
    string $registrationEmail,
    string $registrationName,
    string $registrationPhone,
    string $registrationRole,
    DateTimeImmutable $now,
    string $rid,
    float $sinceStart
): void {
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    $finalizeStart = microtime(true);
    try {
        if (trim($registrationEmail) !== '') {
            try {
                require_once __DIR__ . '/../services/NotificationService.php';
                \NotificationService::notifyWelcome(
                    $db,
                    $locationId,
                    strtolower(trim($registrationEmail)),
                    trim($registrationName) !== '' ? trim($registrationName) : 'NOLA Owner',
                    trim($registrationPhone),
                    trim($registrationRole) !== '' ? trim($registrationRole) : 'user'
                );
            } catch (\Throwable $welcomeError) {
                error_log('[register_from_install] notifyWelcome trigger failed: ' . $welcomeError->getMessage());
            }
        }
    } catch (Exception $finalizeError) {
        error_log('[register_from_install] finalize_registered_fast failed rid=' . $rid . ' location=' . $locationId . ': ' . $finalizeError->getMessage());
    }
    register_from_install_log_timing($rid, 'finalize_fast', $finalizeStart);
    register_from_install_log_timing($rid, 'total', $sinceStart);
}

function _initial_user_credit_balance_for_location($db, string $locationId): int
{
    return users_resolve_initial_credit_balance_for_location($db, $locationId);
}

/**
 * Write (or update) a subaccount record in users/{uid}/subaccounts/{entityId}.
 * For agencies, entityId is companyId. For location users, entityId is locationId.
 */
function _write_user_subaccount(
    $db,
    string $uid,
    string $entityId,
    array $details,
    DateTimeImmutable $now,
    bool $isAgency = false,
    bool $isActive = true
): void
{
    try {
        $subaccountRef = $db->collection('users')->document($uid)->collection('subaccounts')->document($entityId);
        $payload = [
            'company_id' => $details['company_id'] ?? '',
            'role' => $details['role'] ?? 'user',
            'is_active' => $isActive,
            'activation_state' => $isActive ? 'active' : 'pending',
            'linked_at' => new \Google\Cloud\Core\Timestamp($now),
        ];
        $companyName = trim((string)($details['company_name'] ?? ''));
        if ($companyName !== '') {
            $payload['company_name'] = $companyName;
        }

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

function _owner_lock_available_for_email($db, string $collection, string $entityId, string $email): bool
{
    if ($entityId === '') {
        return true;
    }
    try {
        $snap = $db->collection($collection)->document($entityId)->snapshot();
        if (!$snap->exists()) {
            return true;
        }
        $d = $snap->data();
        $ownerEmail = strtolower(trim((string)($d['owner_email'] ?? '')));
        return $ownerEmail === '' || $ownerEmail === strtolower(trim($email));
    } catch (Exception $e) {
        error_log("[register_from_install] _owner_lock_available_for_email failed for {$collection}/{$entityId}: " . $e->getMessage());
        return true;
    }
}

function _upsert_owner_lock($db, string $collection, string $entityId, string $ownerUserId, string $ownerEmail, string $ownerName, DateTimeImmutable $now): void
{
    if ($entityId === '') {
        return;
    }
    if (!install_claim_owner_lock($db, $collection, $entityId, $ownerUserId, $ownerEmail, $ownerName, $now, 'register_from_install_upsert')) {
        error_log("[register_from_install] _upsert_owner_lock conflict for {$collection}/{$entityId}");
    }
}

function _sync_location_owner_metadata($db, string $locationId, string $ownerUserId, string $ownerEmail, string $ownerName, string $ownerPhone, DateTimeImmutable $now): void
{
    if ($locationId === '') {
        return;
    }

    $payload = [
        'location_id' => $locationId,
        'owner_user_id' => $ownerUserId,
        'owner_uid' => $ownerUserId,
        'owner_email' => strtolower(trim($ownerEmail)),
        'owner_name' => $ownerName,
        'owner_phone' => $ownerPhone,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ];

    try {
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId);
        $db->collection('integrations')->document($intDocId)->set($payload, ['merge' => true]);
    } catch (Exception $e) {
        error_log("[register_from_install] _sync_location_owner_metadata integrations failed for {$locationId}: " . $e->getMessage());
    }

    try {
        $db->collection('ghl_tokens')->document($locationId)->set($payload, ['merge' => true]);
    } catch (Exception $e) {
        error_log("[register_from_install] _sync_location_owner_metadata ghl_tokens failed for {$locationId}: " . $e->getMessage());
    }
}
