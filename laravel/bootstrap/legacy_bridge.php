<?php

$legacyScript = $argv[1] ?? '';
$method = strtoupper($argv[2] ?? 'GET');
$queryJson = $argv[3] ?? '{}';
$headersJson = $argv[4] ?? '{}';

if ($legacyScript === '' || !is_file($legacyScript)) {
    http_response_code(500);
    echo json_encode(['error' => 'Legacy script not found']);
    fwrite(STDERR, "__BRIDGE_STATUS__500\n");
    exit(1);
}

$query = json_decode($queryJson, true);
if (!is_array($query)) {
    $query = [];
}

$headers = json_decode($headersJson, true);
if (!is_array($headers)) {
    $headers = [];
}

$_GET = $query;
$_POST = [];
$_REQUEST = $query;
$_COOKIE = $_COOKIE ?? [];

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['QUERY_STRING'] = http_build_query($query);
$_SERVER['HTTP_ORIGIN'] = $_SERVER['HTTP_ORIGIN'] ?? '';

foreach ($headers as $name => $values) {
    $value = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
    if ($value === '') {
        continue;
    }

    $normalized = strtoupper(str_replace('-', '_', (string) $name));
    if ($normalized === 'CONTENT_TYPE' || $normalized === 'CONTENT_LENGTH') {
        $_SERVER[$normalized] = $value;
        continue;
    }

    $_SERVER['HTTP_' . $normalized] = $value;
    if ($normalized === 'AUTHORIZATION') {
        $_SERVER['AUTHORIZATION'] = $value;
        $_SERVER['Authorization'] = $value;
    }
}

register_shutdown_function(static function (): void {
    $status = http_response_code();
    if (!is_int($status) || $status < 100) {
        $status = 200;
    }
    fwrite(STDERR, "__BRIDGE_STATUS__{$status}\n");
});

require $legacyScript;
