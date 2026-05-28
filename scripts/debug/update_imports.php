<?php

function replace_in_file($file_path, $replacements) {
    if (!file_exists($file_path)) {
        return;
    }
    $content = file_get_contents($file_path);
    $modified = false;
    foreach ($replacements as $target => $replacement) {
        if (strpos($content, $target) !== false) {
            $content = str_replace($target, $replacement, $content);
            $modified = true;
        }
    }
    if ($modified) {
        file_put_contents($file_path, $content);
        echo "Updated: $file_path\n";
    } else {
        echo "No changes: $file_path\n";
    }
}

// 1. Update api/admin/ files
$admin_dir = "api/admin";
$admin_replacements = [
    "__DIR__ . '/cors.php'" => "__DIR__ . '/../cors.php'",
    "__DIR__ . '/webhook/firestore_client.php'" => "__DIR__ . '/../webhook/firestore_client.php'",
    "__DIR__ . '/jwt_helper.php'" => "__DIR__ . '/../jwt_helper.php'",
    "__DIR__ . '/auth_helpers.php'" => "__DIR__ . '/../auth_helpers.php'",
    "__DIR__ . '/services/CreditManager.php'" => "__DIR__ . '/../services/CreditManager.php'",
    "__DIR__ . '/../vendor/autoload.php'" => "__DIR__ . '/../../vendor/autoload.php'"
];

if (is_dir($admin_dir)) {
    foreach (scandir($admin_dir) as $filename) {
        if (str_ends_with($filename, ".php")) {
            replace_in_file("$admin_dir/$filename", $admin_replacements);
        }
    }
}

// 2. Update api/oauth/ files
$oauth_dir = "api/oauth";
$oauth_replacements = [
    "__DIR__ . '/api/webhook/firestore_client.php'" => "__DIR__ . '/../webhook/firestore_client.php'",
    "__DIR__ . '/api/jwt_helper.php'" => "__DIR__ . '/../jwt_helper.php'",
    "__DIR__ . '/api/install_helpers.php'" => "__DIR__ . '/../install_helpers.php'"
];

if (is_dir($oauth_dir)) {
    foreach (scandir($oauth_dir) as $filename) {
        if (str_ends_with($filename, ".php")) {
            replace_in_file("$oauth_dir/$filename", $oauth_replacements);
        }
    }
}

// 3. Update api/install/ files
$install_dir = "api/install";
$install_replacements = [
    "__DIR__ . '/api/jwt_helper.php'" => "__DIR__ . '/../jwt_helper.php'",
    "__DIR__ . '/api/webhook/firestore_client.php'" => "__DIR__ . '/../webhook/firestore_client.php'",
    "__DIR__ . '/api/install_helpers.php'" => "__DIR__ . '/../install_helpers.php'",
    "__DIR__ . '/api/auth_helpers.php'" => "__DIR__ . '/../auth_helpers.php'"
];

if (is_dir($install_dir)) {
    foreach (scandir($install_dir) as $filename) {
        if (str_ends_with($filename, ".php")) {
            replace_in_file("$install_dir/$filename", $install_replacements);
        }
    }
}

echo "Path updating complete!\n";
