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
 * Uses the same HMAC-SHA256 signing scheme as login.php.
 *
 * @return array  Decoded token payload (uid, email, role, …)
 * @exit          Sends 401 JSON and exits on failure
 */
function validate_jwt(): array
{
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

    // --- Split into payload.signature ---
    $parts = explode('.', $token, 2);
    if (count($parts) !== 2) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    [$payloadB64, $signature] = $parts;

    // --- Verify HMAC-SHA256 signature ---
    $secret = getenv('AUTH_TOKEN_SECRET') ?: 'nola-sms-pro-auth-secret-2026';
    $expectedSig = rtrim(strtr(base64_encode(hash_hmac('sha256', $payloadB64, $secret, true)), '+/', '-_'), '=');

    if (!hash_equals($expectedSig, $signature)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    // --- Decode payload ---
    $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
    if (!is_array($payload)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    // --- Check expiry ---
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token.']);
        exit;
    }

    return $payload;
}
