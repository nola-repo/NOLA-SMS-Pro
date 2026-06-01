<?php
/**
 * POST /api/auth/forgot_password_otp.php
 * Accepts an email and generates a secure 6-digit OTP code for password reset.
 * Searches admins, agency_users, and users collections by email.
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
$otpCheck = trim($input['otp_check'] ?? '');

// Always return success to prevent user enumeration
$response = ['status' => 'success', 'message' => 'If your email is registered, you will receive an OTP code shortly.'];

if (empty($email)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Email is required']);
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

    // 2. Search in agency_users collection
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

    // 3. Search in users collection
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

    if ($otpCheck !== '') {
        if (!$userDoc) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid email or verification code']);
            exit;
        }

        $userData = $userDoc->data();
        $dbOtp = $userData['otp_code'] ?? null;
        $dbExpires = $userData['otp_expires'] ?? null;
        $dbVerified = $userData['otp_verified'] ?? false;

        if ($dbOtp === null || $dbOtp !== $otpCheck || $dbVerified === true) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or already used verification code']);
            exit;
        }

        $isExpired = true;
        if ($dbExpires instanceof \Google\Cloud\Core\Timestamp) {
            $isExpired = (time() > $dbExpires->get()->getTimestamp());
        }

        if ($isExpired) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Verification code has expired']);
            exit;
        }

        // Return successful verification
        echo json_encode(['status' => 'success', 'message' => 'Verification code verified successfully']);
        exit;
    }

    if ($userDoc) {
        // Generate secure 6-digit OTP
        $otp = (string)random_int(100000, 999999);
        $expires = new \Google\Cloud\Core\Timestamp(new \DateTime('+10 minutes'));

        // Save OTP info to firestore document
        $userRef = $db->collection($userCollection)->document($userDoc->id());
        $userRef->set([
            'otp_code' => $otp,
            'otp_expires' => $expires,
            'otp_verified' => false,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        // Trigger GHL OTP Notification
        try {
            require_once __DIR__ . '/../services/NotificationService.php';
            \NotificationService::notifyForgotPasswordOtp($db, $email, $otp);
        } catch (\Throwable $e) {
            error_log("[forgot_password_otp] GHL Notification Service failed: " . $e->getMessage());
        }

        // Send OTP email with beautiful design
        $subject = "Your 6-Digit Password Reset Verification Code";
        $headers = "From: noreply@nolacrm.io\r\n" .
                   "Reply-To: support@nolacrm.io\r\n" .
                   "Content-Type: text/html; charset=UTF-8\r\n" .
                   "X-Mailer: PHP/" . phpversion();

        $message = "
        <html>
        <head>
            <title>Reset Your Password</title>
        </head>
        <body style=\"font-family: 'Poppins', sans-serif; background-color: #0a0a0b; color: #f4f6fa; padding: 20px;\">
            <div style=\"max-width: 480px; margin: 0 auto; background-color: #1a1b1e; padding: 40px; border-radius: 20px; border: 1px solid rgba(255,255,255,0.1);\">
                <h2 style=\"color: #2b83fa; text-align: center;\">NOLA SMS PRO</h2>
                <p>Hello,</p>
                <p>We received a request to reset your password. Use the verification code below to proceed. This code is valid for <strong>10 minutes</strong>.</p>
                <div style=\"background-color: rgba(43, 131, 250, 0.1); border: 1px solid rgba(43, 131, 250, 0.3); border-radius: 12px; padding: 15px; text-align: center; margin: 30px 0;\">
                    <span style=\"font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #2b83fa;\">{$otp}</span>
                </div>
                <p style=\"font-size: 12px; color: #94a3b8; text-align: center;\">If you did not request this, you can safely ignore this email.</p>
            </div>
        </body>
        </html>";

        @mail($email, $subject, $message, $headers);
        error_log("[forgot_password_otp] Sent OTP code {$otp} to: {$email}");
    } else {
        error_log("[forgot_password_otp] Email not found in any collection: {$email}");
    }

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    error_log("[forgot_password_otp] Error: " . $e->getMessage());
    // Return success to hide information about email existance
    echo json_encode($response);
    exit;
}
