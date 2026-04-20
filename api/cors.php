<?php

/**
 * Handles CORS headers and OPTIONS preflight requests.
 */

$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';

// If credentials are required, Access-Control-Allow-Origin cannot be '*'
// We mirror the origin or use a fallback if missing.
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE, PATCH');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Webhook-Secret, X-GHL-Location-ID, X-GHL-LocationID, X-Agency-ID');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle OPTIONS preflight request
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
