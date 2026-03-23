<?php

require __DIR__ . '/webhook/firestore_client.php';

$db = get_firestore();

$username = 'admin';
$password = 'admin123';
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$adminData = [
    'password' => $hashedPassword,
    'role' => 'super_admin',
    'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
    'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
];

try {
    $db->collection('admins')->document($username)->set($adminData);
    echo "Admin user '$username' created successfully.\n";
} catch (Exception $e) {
    echo "Error creating admin user: " . $e->getMessage() . "\n";
}
