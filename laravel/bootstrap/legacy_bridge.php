<?php

$legacyScript = $argv[1] ?? '';
$method = strtoupper($argv[2] ?? 'GET');
$queryJson = $argv[3] ?? '{}';

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

$_GET = $query;
$_POST = [];
$_REQUEST = $query;
$_COOKIE = $_COOKIE ?? [];

$_SERVER['REQUEST_METHOD'] = $method;
$_SERVER['QUERY_STRING'] = http_build_query($query);
$_SERVER['HTTP_ORIGIN'] = $_SERVER['HTTP_ORIGIN'] ?? '';

register_shutdown_function(static function (): void {
    $status = http_response_code();
    if (!is_int($status) || $status < 100) {
        $status = 200;
    }
    fwrite(STDERR, "__BRIDGE_STATUS__{$status}\n");
});

require $legacyScript;
