<?php

/**
 * Handles CORS headers and OPTIONS preflight requests.
 * Apache (.htaccess) now sets Access-Control-Allow-* headers globally via
 * "Header always set", so we only need to handle the OPTIONS early-exit here
 * as a safety net for environments where .htaccess rewrite isn't active.
 */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
