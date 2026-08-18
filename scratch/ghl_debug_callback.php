<?php
/**
 * TEMPORARY DEBUG — ghl_debug_callback.php
 * Map this to /oauth/debug-callback in Nginx temporarily,
 * OR just visit https://smspro-api.nolacrm.io/ghl_debug_callback.php?secret=nola_debug_2026
 * REMOVE THIS FILE after diagnosis.
 */

$secret = $_GET['secret'] ?? '';
if ($secret !== 'nola_debug_2026') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// Read the last 200 lines of PHP error log
$logCandidates = [
    '/var/log/php/error.log',
    '/var/log/php8.1-fpm.log',
    '/var/log/php8.2-fpm.log',
    '/var/log/nginx/error.log',
    '/tmp/php_errors.log',
    ini_get('error_log'),
];

$logContent = '';
foreach ($logCandidates as $f) {
    if ($f && file_exists($f) && is_readable($f)) {
        $lines = file($f);
        $relevant = array_filter($lines, fn($l) => strpos($l, 'GHL_CALLBACK_DEBUG') !== false);
        if ($relevant) {
            $logContent = implode('', array_slice(array_values($relevant), -30));
            $logFile = $f;
            break;
        }
    }
}

echo '<!DOCTYPE html><html><head><title>GHL Debug</title>
<style>body{font-family:monospace;background:#111;color:#0f0;padding:20px;}
pre{background:#1a1a1a;padding:16px;border-radius:8px;overflow:auto;white-space:pre-wrap;word-break:break-all;font-size:12px;}
h2{color:#7bf;}</style></head><body>';

echo '<h2>GHL Callback Debug</h2>';
echo '<p>Log file: <strong>' . htmlspecialchars($logFile ?? 'NOT FOUND') . '</strong></p>';

if ($logContent) {
    echo '<h2>Last GHL_CALLBACK_DEBUG entries:</h2>';
    echo '<pre>' . htmlspecialchars($logContent) . '</pre>';
} else {
    echo '<p style="color:red;">No GHL_CALLBACK_DEBUG entries found in known log files.</p>';
    echo '<p>Checked:</p><ul>';
    foreach ($logCandidates as $f) {
        echo '<li>' . htmlspecialchars($f ?: '(empty)') . ' — ' . (file_exists((string)$f) ? 'EXISTS' : 'NOT FOUND') . '</li>';
    }
    echo '</ul>';
    echo '<p>PHP error_log setting: <strong>' . htmlspecialchars(ini_get('error_log') ?: '(not set)') . '</strong></p>';
}

echo '</body></html>';
