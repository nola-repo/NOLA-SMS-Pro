<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

// CORS + preflight (Web UI direct calls)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '*';
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . $origin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Webhook-Secret');
header('Access-Control-Max-Age: 86400');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Method not allowed',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $limit  = min((int)($_GET['limit'] ?? 50), 100);
    $offset = max((int)($_GET['offset'] ?? 0), 0);
    $type   = $_GET['type'] ?? null; // optional: direct | bulk

    $query = $db->collection('conversations')
        ->orderBy('last_message_at', 'DESC')
        ->limit($limit)
        ->offset($offset);

    $rows = [];
    foreach ($query->documents() as $doc) {
        if (!$doc->exists()) continue;
        $d = $doc->data();

        $row = [
            'id'              => $doc->id(),
            'type'            => $d['type'] ?? null,
            'members'         => $d['members'] ?? [],
            'name'            => $d['name'] ?? null,
            'last_message'    => $d['last_message'] ?? null,
            'last_message_at' => isset($d['last_message_at']) ? $d['last_message_at']->formatAsString() : null,
            'updated_at'      => isset($d['updated_at']) ? $d['updated_at']->formatAsString() : null,
        ];

        if ($type && ($row['type'] ?? '') !== $type) {
            continue;
        }

        $rows[] = $row;
    }

    echo json_encode([
        'success' => true,
        'data'    => $rows,
        'limit'   => $limit,
        'offset'  => $offset,
    ], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to fetch conversations',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

