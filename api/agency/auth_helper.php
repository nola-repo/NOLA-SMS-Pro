<?php

function validate_agency_request($require_agency_id = true) {
    $receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
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

    return $receivedAgencyId;
}
