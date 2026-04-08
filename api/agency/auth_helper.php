<?php

/**
 * Validates agency requests via two supported methods (in priority order):
 *
 * 1. JWT Bearer Token — Used by the Agency Portal frontend after login/SSO.
 *    Extracts company_id from the token payload.
 *
 * 2. X-Webhook-Secret + X-Agency-ID Headers — Used by internal server-to-server
 *    calls and legacy integrations. Kept for full backward compatibility.
 *
 * @param bool $require_agency_id  Whether to enforce a company/agency ID on the request.
 * @return string                  The resolved agency/company ID.
 */
function validate_agency_request($require_agency_id = true): string {

    // ── Method 1: JWT Bearer Token ─────────────────────────────────────────────
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

    if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
        // Delegate to the shared JWT validator
        require_once __DIR__ . '/../auth_helpers.php';
        $payload = validate_jwt(); // exits with 401 on failure

        // Enforce agency role
        if (($payload['role'] ?? '') !== 'agency') {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Forbidden: Agency role required']);
            exit;
        }

        // Extract company_id from token (set by both ghl_autologin.php & login.php)
        $companyId = $payload['company_id'] ?? $payload['agency_id'] ?? '';

        if ($require_agency_id && empty($companyId)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Bad Request: No company_id in token']);
            exit;
        }

        return (string) $companyId;
    }

    // ── Method 2: Legacy Webhook Secret + X-Agency-ID Headers ─────────────────
    $receivedSecret   = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
    $receivedAgencyId = $_SERVER['HTTP_X_AGENCY_ID'] ?? '';

    // Fallback if Nginx formats custom headers differently
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!$receivedSecret || ($require_agency_id && !$receivedAgencyId)) {
        foreach ($headers as $key => $value) {
            if (!$receivedSecret && strcasecmp($key, 'X-Webhook-Secret') === 0) {
                $receivedSecret = $value;
            }
            if ($require_agency_id && !$receivedAgencyId && strcasecmp($key, 'X-Agency-ID') === 0) {
                $receivedAgencyId = $value;
            }
        }
    }

    $expectedSecret = getenv('WEBHOOK_SECRET') ?: 'f7RkQ2pL9zV3tX8cB1nS4yW6';

    if (!$receivedSecret || !hash_equals($expectedSecret, $receivedSecret)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: Invalid or missing webhook secret']);
        exit;
    }

    if ($require_agency_id && empty($receivedAgencyId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Bad Request: Missing X-Agency-ID']);
        exit;
    }

    return (string) $receivedAgencyId;
}
