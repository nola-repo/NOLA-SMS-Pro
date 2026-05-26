<?php

// Mock necessary globals
$_SERVER = [];

require_once __DIR__ . '/../api/jwt_helper.php';

// Redefine/mock built-in functions or headers if needed
function simulate_run($headers, $tokenSecret = 'nola-super-admin-secret') {
    $_SERVER = [];
    foreach ($headers as $k => $v) {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($k));
        $_SERVER[$serverKey] = $v;
    }
    
    // We will capture output and response code
    ob_start();
    
    // Simple helper to capture response code
    if (!function_exists('http_response_code')) {
        function http_response_code($code = NULL) {
            static $curr = 200;
            if ($code !== NULL) $curr = $code;
            return $curr;
        }
    }
    
    $code = file_get_contents(__DIR__ . '/../api/admin_list_users.php');
    // Extract the require_admin_auth function definition
    preg_match('/function require_admin_auth\(\).*?\n}/s', $code, $matches);
    
    if (empty($matches)) {
        echo "Failed to extract require_admin_auth\n";
        return;
    }
    
    $funcCode = $matches[0];
    
    // Run the extracted function definition
    try {
        if (!function_exists('require_admin_auth')) {
            eval($funcCode);
        }
        
        $claims = require_admin_auth();
        ob_end_clean();
        return ['status' => 'success', 'claims' => $claims];
    } catch (Exception $e) {
        ob_end_clean();
        return ['status' => 'error', 'message' => $e->getMessage()];
    }
}

// Test Case 1: Legacy headers
echo "Test Case 1: Legacy headers active...\n";
$res1 = simulate_run([
    'X-Admin-Auth' => 'true',
    'X-Admin-User' => 'super-admin-username'
]);
var_dump($res1);

// Test Case 2: Missing token
echo "\nTest Case 2: Missing token...\n";
$code = file_get_contents(__DIR__ . '/../api/admin_list_users.php');
preg_match('/function require_admin_auth\(\).*?\n}/s', $code, $matches);
$funcCode = $matches[0];
$funcCode = str_replace('exit;', 'throw new Exception("exit_called");', $funcCode);
$funcCode = str_replace('require_admin_auth', 'require_admin_auth_test', $funcCode);

eval($funcCode);

function run_test_case($headers) {
    $_SERVER = [];
    foreach ($headers as $k => $v) {
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($k));
        $_SERVER[$serverKey] = $v;
    }
    
    ob_start();
    try {
        $claims = require_admin_auth_test();
        ob_end_clean();
        return ['success' => true, 'claims' => $claims];
    } catch (Exception $e) {
        $output = ob_get_clean();
        return ['success' => false, 'output' => json_decode($output, true), 'code' => http_response_code()];
    }
}

// Test Case 2: Missing token
$res2 = run_test_case([]);
echo "Result for missing token:\n";
var_dump($res2);

// Test Case 3: Invalid token format
$res3 = run_test_case([
    'Authorization' => 'Bearer not.a.jwt'
]);
echo "\nResult for invalid token:\n";
var_dump($res3);

// Test Case 4: Expired token
$secret = getenv('JWT_SECRET') ?: 'nola-super-admin-secret';
$expiredToken = jwt_sign(['username' => 'admin', 'role' => 'super_admin'], $secret, -3600); // 1 hour ago
$res4 = run_test_case([
    'Authorization' => 'Bearer ' . $expiredToken
]);
echo "\nResult for expired token:\n";
var_dump($res4);

// Test Case 5: Valid token
$validToken = jwt_sign(['username' => 'admin', 'role' => 'super_admin'], $secret, 3600); // 1 hour expiry
$res5 = run_test_case([
    'Authorization' => 'Bearer ' . $validToken
]);
echo "\nResult for valid token:\n";
var_dump($res5);
