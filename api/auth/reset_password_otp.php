<?php
/**
 * POST /api/auth/reset_password_otp.php
 * Accepts an email, a 6-digit OTP code, and the new password.
 * Verifies the OTP, updates the password hash in admins, agency_users, or users.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/../webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];
$email = strtolower(trim($input['email'] ?? ''));
$otp = trim($input['otp'] ?? '');
$newPassword = $input['new_password'] ?? '';

if (empty($email) || empty($otp) || empty($newPassword)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email, OTP code, and new password are required']);
    exit;
}

if (strlen($newPassword) < 8) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Password must be at least 8 characters long']);
    exit;
}

try {
    $db = get_firestore();
    $userDoc = null;
    $userCollection = null;

    // 1. Direct fetch from admins collection
    $adminRef = $db->collection('admins')->document($email);
    $adminSnap = $adminRef->snapshot();
    if ($adminSnap->exists()) {
        $userDoc = $adminSnap;
        $userCollection = 'admins';
    }

    // 2. Search in admins collection by email for pre-migration username-ID docs
    if (!$userDoc) {
        $results = $db->collection('admins')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();
        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userDoc = $doc;
                $userCollection = 'admins';
                break;
            }
        }
    }

    // 3. Search in agency_users collection
    if (!$userDoc) {
        $results = $db->collection('agency_users')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();
        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userDoc = $doc;
                $userCollection = 'agency_users';
                break;
            }
        }
    }

    // 4. Search in users collection
    if (!$userDoc) {
        $results = $db->collection('users')
            ->where('email', '=', $email)
            ->limit(1)
            ->documents();
        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userDoc = $doc;
                $userCollection = 'users';
                break;
            }
        }
    }

    if (!$userDoc) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid email or OTP code']);
        exit;
    }

    $userData = $userDoc->data();
    $dbOtp = $userData['otp_code'] ?? null;
    $dbExpires = $userData['otp_expires'] ?? null;
    $dbVerified = $userData['otp_verified'] ?? false;

    // Validate OTP matching
    if ($dbOtp === null || $dbOtp !== $otp || $dbVerified === true) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Invalid or already used OTP code']);
        exit;
    }

    // Validate expiration
    $isExpired = true;
    if ($dbExpires instanceof \Google\Cloud\Core\Timestamp) {
        $isExpired = (time() > $dbExpires->get()->getTimestamp());
    }

    if ($isExpired) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'OTP code has expired']);
        exit;
    }

    // Hashed Password - admin has hashed_password or password_hash. Let's write both to ensure compatibility
    $hash = password_hash($newPassword, PASSWORD_BCRYPT);
    $userRef = $db->collection($userCollection)->document($userDoc->id());

    $updates = [
        'password_hash' => $hash,
        'hashed_password' => $hash, // compatibility for admin
        'otp_code' => null,
        'otp_expires' => null,
        'otp_verified' => true,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    ];

    $userRef->set($updates, ['merge' => true]);

    echo json_encode(['status' => 'success', 'message' => 'Password has been successfully updated']);
    exit;

} catch (Exception $e) {
    error_log("[reset_password_otp] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error: ' . $e->getMessage()]);
    exit;
}
