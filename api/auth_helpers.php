<?php

/**
 * Validates API key from X-Webhook-Secret header.
 * Call at the top of protected endpoints.
 */
function validate_api_request(): void
{
    $expectedSecret = getenv('WEBHOOK_SECRET');
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';

    if (!$expectedSecret) {
        return; // No secret configured, allow (dev/legacy)
    }

    if (!hash_equals($expectedSecret, $receivedSecret)) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
        exit;
    }
}
