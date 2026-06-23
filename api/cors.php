<?php

/**
 * Handles CORS headers and OPTIONS preflight requests.
 */

// ── Logging ───────────────────────────────────────────────────────────────────
// The logger is the very first thing loaded so every request is captured.
// Logger::init() is idempotent — safe even if cors.php is included multiple times.
require_once __DIR__ . '/logger.php';
Logger::init();
// ─────────────────────────────────────────────────────────────────────────────

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$configuredOrigins = array_filter(array_map('trim', explode(',', (string) (getenv('CORS_ALLOWED_ORIGINS') ?: ''))));
$trustedProductOrigins = [
    'https://smspro.nolacrm.io',
    'https://app.nolacrm.io',
    'https://app.nolasmspro.com',
    'https://agency.nolasmspro.com',
    'https://app.gohighlevel.com',
    'https://app.highlevel.com',
];
$localOrigins = [
    'http://localhost:3000',
    'http://localhost:5173',
];
$allowedOrigins = $configuredOrigins
    ? array_values(array_unique(array_merge($configuredOrigins, $trustedProductOrigins)))
    : array_merge($trustedProductOrigins, $localOrigins);
$allowOrigin = in_array($origin, $allowedOrigins, true) ? $origin : '';

// If credentials are required, Access-Control-Allow-Origin cannot be '*'
// We only mirror explicitly trusted origins.
if ($allowOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $allowOrigin);
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Authorization, X-Auth-Token, X-Webhook-Secret, X-GHL-Location-ID, X-GHL-LocationID, X-Agency-ID, X-Request-ID, X-Correlation-ID, Idempotency-Key');
header('Access-Control-Expose-Headers: X-Request-ID, X-Correlation-ID, X-Nola-Cache');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    Logger::response(204);
    http_response_code(204);
    exit;
}

