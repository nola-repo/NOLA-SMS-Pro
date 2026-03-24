<?php
/**
 * Raw Request Catcher for GHL Debugging
 * Saves headers and body to a JSON file for inspection.
 */

// 1. Capture Headers
$headers = function_exists('getallheaders') ? getallheaders() : [];

// 2. Capture Body
$raw_body = file_get_contents('php://input');
$json_body = json_decode($raw_body, true);

// 3. Prepare Debug Info
$debug_info = [
    'timestamp' => date('Y-m-d H:i:s'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'uri' => $_SERVER['REQUEST_URI'] ?? 'UNKNOWN',
    'headers' => $headers,
    'raw_body' => $raw_body,
    'json_body' => $json_body,
    'get_params' => $_GET,
    'post_params' => $_POST
];

// 4. Save to /tmp for easy viewing
$log_file = sys_get_temp_dir() . '/ghl_debug_raw.json';
file_put_contents($log_file, json_encode($debug_info, JSON_PRETTY_PRINT));

// 5. Handle VIEWING via GET
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: application/json');
    if (file_exists($log_file)) {
        echo file_get_contents($log_file);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No log found yet. Send a POST request from GHL first.']);
    }
    exit;
}

// 6. ALWAYS return 200 SUCCESS to GHL
// This prevents GHL from "skipping" the action so we can see if it's hitting our server.
header('Content-Type: application/json');
http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Payload captured for debugging.',
    'log_saved_to' => $log_file
]);
