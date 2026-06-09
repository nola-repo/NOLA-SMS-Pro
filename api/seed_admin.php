<?php

$appEnv = strtolower((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: 'production'));
if ($appEnv === 'production') {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

require __DIR__ . '/webhook/firestore_client.php';

$db = get_firestore();

$email = strtolower(trim(getenv('ADMIN_EMAIL') ?: 'admin@example.com'));
$password = 'admin123';
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$adminData = [
    'email' => $email,
    'hashed_password' => $hashedPassword,
    'role' => 'super_admin',
    'active' => true,
    'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
];

try {
    $db->collection('admins')->document($email)->set($adminData);
    echo "Admin user '$email' created successfully.\n";
} catch (Exception $e) {
    echo "Error creating admin user: " . $e->getMessage() . "\n";
}
