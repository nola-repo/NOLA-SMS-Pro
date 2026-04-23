<?php

/**
 * Validates API key from X-Webhook-Secret header.
 * Call at the top of protected endpoints.
 */
function validate_api_request(): void
{
    // Try standard PHP server headers first
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

    if (!$receivedSecret) {
        // Fallback: search all headers for the secret (Apache/Cloud Run compatibility)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-Webhook-Secret') === 0) {
                $receivedSecret = $value;
                break;
            }
        }
    }

    // Fallback: Check Query String for Webhooks that don't support custom headers (e.g Semaphore, CRON)
    if (!$receivedSecret) {
        $receivedSecret = $_GET['secret'] ?? $_GET['token'] ?? '';
    }

    // Use the value from CLOUD-RUN-SECRETS.md as fallback if env not set
    $expectedSecret = getenv('WEBHOOK_SECRET') ?: 'f7RkQ2pL9zV3tX8cB1nS4yW6';

    if (!hash_equals($expectedSecret, (string)$receivedSecret)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized Access']);
        exit;
    }
}

/**
 * Gets the GHL Location ID from headers or query parameters.
 * Required for multi-tenant data scoping.
 */
function get_ghl_location_id(): ?string
{
    // Try standard PHP server headers (case-insensitive search)
    $locId = $_SERVER['HTTP_X_GHL_LOCATION_ID'] ??
        $_SERVER['HTTP_X_GHL_LOCATIONID'] ??
        null;

    if (!$locId) {
        // Fallback to searching all headers (some environments don't use HTTP_ prefix correctly)
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-GHL-Location-Id') === 0 || strcasecmp($key, 'X-GHL-LocationID') === 0) {
                $locId = $value;
                break;
            }
        }
    }

    if (!$locId) {
        $locId = $_GET['location_id'] ?? $_GET['locationId'] ?? null;
    }

    // Special robust handling for GHL's dynamic values:
    // If it looks like a variable template that wasn't replaced, treat as null
    if ($locId && strpos((string)$locId, '{{') !== false) {
        return null;
    }

    return $locId ? (string)$locId : null;
}

/**
 * Validates the JWT from the Authorization: Bearer header.
 * Uses the centralized jwt_helper.php library.
 *
 * @return array  Decoded token payload (sub, email, role, …)
 * @exit          Sends 401 JSON and exits on failure
 */
function validate_jwt(): array
{
    require_once __DIR__ . '/jwt_helper.php';

    // --- Extract the Bearer token ---
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    $token = substr($authHeader, 7); // strip "Bearer "
    $secret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

    $payload = jwt_verify($token, $secret);

    if (!$payload) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    return $payload;
}
