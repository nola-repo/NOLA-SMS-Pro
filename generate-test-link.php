<?php
/**
 * Helper script to generate a signed JWT installation token for local testing of install-register.php.
 * Run this in terminal via: php generate-test-link.php
 * Or access via browser: http://localhost:8000/generate-test-link.php
 */

require_once __DIR__ . '/api/jwt_helper.php';

// Try to load JWT_SECRET from environment or fallback to a dummy secret for testing
$jwtSecret = getenv('JWT_SECRET');
if ($jwtSecret === false || trim((string)$jwtSecret) === '') {
    $jwtSecret = 'dummy_secret_for_local_test';
}

$payload = [
    'type' => 'install',
    'location_id' => 'loc_local_test_123',
    'location_name' => 'Local Test Sub-Account',
    'company_id' => 'co_local_test_456',
    'company_name' => 'Local Test Company',
    'resolution_source' => 'local_debug_tool'
];

// Generate token (valid for 2 hours)
$token = jwt_sign($payload, $jwtSecret, 7200);

$port = isset($_SERVER['SERVER_PORT']) ? $_SERVER['SERVER_PORT'] : '8000';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost:' . $port;
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";

$browserUrl = "$protocol://$host/install-register.php?install_token=" . $token;

if (php_sapi_name() === 'cli') {
    echo "\n=== NOLA SMS Pro Local Registration Test Link Generator ===\n";
    echo "Using JWT_SECRET: " . (getenv('JWT_SECRET') ? "Loaded from environment" : "None detected (using fallback 'dummy_secret_for_local_test')") . "\n\n";
    echo "Click or copy the URL below to view the registration form on your local server:\n";
    echo "=> http://localhost:8000/install-register.php?install_token=" . $token . "\n\n";
    echo "Note: If your local server runs on a different port, replace 8000 with your port.\n";
    echo "========================================================\n\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
<!DOCTYPE html>
<html>
<head>
    <title>Registration Test Link</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f6fa; padding: 40px; text-align: center; color: #333; }
        .card { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); }
        h1 { font-size: 24px; color: #111; margin-bottom: 20px; }
        p { font-size: 14px; color: #666; line-height: 1.6; margin-bottom: 30px; }
        .btn { display: inline-block; padding: 14px 28px; background: #2b83fa; color: white; text-decoration: none; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(43, 131, 250, 0.2); transition: all 0.2s; }
        .btn:hover { background: #1d6bd4; transform: translateY(-1px); }
        .code-box { background: #f8fafc; padding: 15px; border-radius: 10px; border: 1px solid #e2e8f0; font-family: monospace; font-size: 12px; overflow-x: auto; text-align: left; margin-bottom: 20px; word-break: break-all; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Local Registration Test Link</h1>
        <p>Your local JWT token has been generated. Click the button below to open the registration page directly on your local web server:</p>
        <a class="btn" href="{$browserUrl}">Open Registration Form</a>
        <div style="margin-top: 30px; text-align: left;">
            <span style="font-size: 12px; font-weight: 700; color: #6e6e73; text-transform: uppercase;">Direct URL:</span>
            <div class="code-box">{$browserUrl}</div>
        </div>
    </div>
</body>
</html>
HTML;
}
