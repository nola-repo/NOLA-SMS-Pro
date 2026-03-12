<?php

/**
 * Validates API key from X-Webhook-Secret header.
 * Call at the top of protected endpoints.
 */
function validate_api_request(): void
{
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
    // Use the value from CLOUD-RUN-SECRETS.md as fallback if env not set
    $expectedSecret = getenv('WEBHOOK_SECRET') ?: 'f7RkQ2pL9zV3tX8cB1nS4yW6';

    if (!hash_equals($expectedSecret, $receivedSecret)) {
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
    $locId = $_SERVER['HTTP_X_GHL_LOCATION_ID'] ?? $_GET['location_id'] ?? $_GET['locationId'] ?? null;
    return $locId ? (string)$locId : null;
}
