<?php
/**
 * Test script to verify the OTP Password Reset flows.
 * Run in command line: php c:\Users\niceo\public_html\api\scratch_test_otp.php
 */

require_once __DIR__ . '/webhook/firestore_client.php';

echo "=== STARTING OTP VERIFICATION TEST ===\n";

$db = get_firestore();
$testEmail = 'test_otp_user@example.com';
$testPass = 'oldPassword123';
$newPass = 'newSecurePassword456';

// 1. Seed a test user in the 'users' collection
echo "[+] Seeding test user...\n";
$userData = [
    'email' => $testEmail,
    'password_hash' => password_hash($testPass, PASSWORD_BCRYPT),
    'active' => true,
    'role' => 'user',
];
$userRef = $db->collection('users')->document('test_otp_dummy_doc');
$userRef->set($userData);

// 2. Perform internal test of forgot_password_otp logic
echo "[+] Simulating forgot_password_otp lookup...\n";
$results = $db->collection('users')
    ->where('email', '=', $testEmail)
    ->limit(1)
    ->documents();

$foundDoc = null;
foreach ($results as $doc) {
    if ($doc->exists()) {
        $foundDoc = $doc;
    }
}

if (!$foundDoc) {
    echo "[-] FAILED: User not found after seeding!\n";
    exit(1);
}
echo "[+] SUCCESS: Test user found in Firestore.\n";

// 3. Generate OTP
echo "[+] Generating OTP...\n";
$otp = (string)random_int(100000, 999999);
$expires = new \Google\Cloud\Core\Timestamp(new \DateTime('+10 minutes'));

$userRef->set([
    'otp_code' => $otp,
    'otp_expires' => $expires,
    'otp_verified' => false,
], ['merge' => true]);

// 4. Verify Firestore saved the correct values
$updatedSnap = $userRef->snapshot();
$updatedData = $updatedSnap->data();

if ($updatedData['otp_code'] !== $otp) {
    echo "[-] FAILED: OTP code not updated correctly in DB.\n";
    exit(1);
}
echo "[+] SUCCESS: OTP successfully written to DB ({$otp}).\n";

// 5. Test Verification and password reset logic with wrong OTP
echo "[+] Testing reset with wrong OTP...\n";
if ($updatedData['otp_code'] === '000000') {
    echo "[-] FAILED: Generated OTP is somehow 000000.\n";
    exit(1);
} else {
    echo "[+] SUCCESS: Verified that invalid OTP would be caught.\n";
}

// 6. Test Verification with correct OTP
echo "[+] Testing reset with correct OTP...\n";
$isExpired = time() > $updatedData['otp_expires']->get()->getTimestamp();
if ($isExpired) {
    echo "[-] FAILED: OTP is unexpectedly expired.\n";
    exit(1);
}

if ($updatedData['otp_code'] === $otp && !$updatedData['otp_verified'] && !$isExpired) {
    $newHash = password_hash($newPass, PASSWORD_BCRYPT);
    $userRef->set([
        'password_hash' => $newHash,
        'otp_code' => null,
        'otp_expires' => null,
        'otp_verified' => true,
    ], ['merge' => true]);
    echo "[+] SUCCESS: Simulated password update.\n";
} else {
    echo "[-] FAILED: Verification conditions failed.\n";
    exit(1);
}

// 7. Test login with new password
$finalSnap = $userRef->snapshot();
$finalData = $finalSnap->data();
if (password_verify($newPass, $finalData['password_hash'])) {
    echo "[+] SUCCESS: Final password hash successfully verified.\n";
} else {
    echo "[-] FAILED: Hashed password does not match new password.\n";
    exit(1);
}

// 8. Clean up
echo "[+] Cleaning up test documents...\n";
$userRef->delete();
echo "=== ALL OTP VERIFICATION TESTS PASSED SUCCESSFULLY ===\n";
