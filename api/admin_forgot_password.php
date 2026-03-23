<?php

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$username = $input['username'] ?? '';

// To prevent user enumeration, we always return success
$response = ['status' => 'success', 'message' => 'Reset email sent'];

if (empty($username)) {
    echo json_encode($response);
    exit;
}

$db = get_firestore();

try {
    $adminRef = $db->collection('admins')->document($username);
    $snapshot = $adminRef->snapshot();

    if ($snapshot->exists()) {
        $data = $snapshot->data();
        $email = $data['email'] ?? null;
        
        // Generate a secure reset token
        $token = bin2hex(random_bytes(32));
        $expires = new \Google\Cloud\Core\Timestamp(new \DateTime('+1 hour'));

        // Save token to admin document
        $adminRef->set([
            'reset_token' => $token,
            'reset_expires' => $expires,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
        ], ['merge' => true]);

        // Send email if address exists
        if ($email) {
            send_reset_email($email, $token);
        }
    }

    echo json_encode($response);
    exit;

} catch (Exception $e) {
    // Log error but still return success to the client
    error_log("Forgot password error: " . $e->getMessage());
    echo json_encode($response);
    exit;
}

/**
 * Placeholder for sending reset email.
 * Replace with your email service (e.g., SendGrid, Mailgun).
 */
function send_reset_email($to, $token)
{
    $subject = "Admin Password Reset - NOLA SMS Pro";
    $resetLink = "https://smspro.nolacrm.io/admin/reset-password?token=" . $token;
    $message = "To reset your password, please click the link below:\n\n" . $resetLink . "\n\nThis link will expire in 1 hour.";
    $headers = "From: noreply@nolacrm.io";

    // In a real scenario, use a library like PHPMailer or a 3rd-party API
    @mail($to, $subject, $message, $headers);
}
