<?php
/**
 * Redacted request catcher for non-production GHL debugging.
 */

$appEnv = strtolower((string) (getenv('APP_ENV') ?: getenv('ENVIRONMENT') ?: 'production'));
if ($appEnv === 'production') {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => 'Not found']);
    exit;
}

function debug_ghl_hash(?string $value): ?string
{
    if ($value === null || $value === '') {
        return null;
    }
    return hash('sha256', $value);
}

function debug_ghl_redact_key(string $key, $value)
{
    $sensitive = ['authorization', 'token', 'secret', 'password', 'key', 'signature', 'phone', 'email', 'message', 'body'];
    $lower = strtolower($key);
    foreach ($sensitive as $needle) {
        if (strpos($lower, $needle) !== false) {
            return '[REDACTED:' . debug_ghl_hash(is_scalar($value) ? (string) $value : json_encode($value)) . ']';
        }
    }
    return $value;
}

function debug_ghl_redact_array(array $data): array
{
    $out = [];
    foreach ($data as $key => $value) {
        $keyText = is_string($key) ? $key : (string) $key;
        if (is_array($value)) {
            $out[$key] = debug_ghl_redact_key($keyText, debug_ghl_redact_array($value));
        } else {
            $out[$key] = debug_ghl_redact_key($keyText, $value);
        }
    }
    return $out;
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$rawBody = file_get_contents('php://input') ?: '';
$jsonBody = json_decode($rawBody, true);

$debugInfo = [
    'timestamp' => date('c'),
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    'uri_hash' => debug_ghl_hash($_SERVER['REQUEST_URI'] ?? ''),
    'headers' => debug_ghl_redact_array(is_array($headers) ? $headers : []),
    'body_sha256' => debug_ghl_hash($rawBody),
    'body_json' => is_array($jsonBody) ? debug_ghl_redact_array($jsonBody) : null,
    'get_params' => debug_ghl_redact_array($_GET),
    'post_params' => debug_ghl_redact_array($_POST),
];

$logFile = sys_get_temp_dir() . '/ghl_debug_redacted.json';
file_put_contents($logFile, json_encode($debugInfo, JSON_PRETTY_PRINT));

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($logFile)) {
        echo file_get_contents($logFile);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'No redacted debug snapshot found.']);
    }
    exit;
}

http_response_code(200);
echo json_encode([
    'status' => 'success',
    'message' => 'Redacted payload snapshot captured.',
]);