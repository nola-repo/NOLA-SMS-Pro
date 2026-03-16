<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function contacts_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
}

try {
    if ($method === 'GET') {
        $limit  = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $phone  = $_GET['phone'] ?? null;

        $locId = get_ghl_location_id();
        if (!$locId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing location_id']);
            exit;
        }

        $q = $db->collection('contacts')
            ->where('location_id', '==', $locId)
            ->orderBy('created_at', 'DESC');

        if ($phone) {
            $q = $q->where('phone', '==', $phone);
        }

        $query = $q->limit($limit)
            ->offset($offset);

        $results = [];
        foreach ($query->documents() as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            $results[] = [
                'id'         => $doc->id(),
                'name'       => $d['name'] ?? null,
                'phone'      => $d['phone'] ?? null,
                'email'      => $d['email'] ?? null,
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'updated_at' => isset($d['updated_at']) ? $d['updated_at']->formatAsString() : null,
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => $results,
            'limit'   => $limit,
            'offset'  => $offset,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $body  = contacts_json_body();
        $name  = trim($body['name'] ?? '');
        $phone = trim($body['phone'] ?? '');
        $email = trim($body['email'] ?? '');

        if (!$phone) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Phone is required'], JSON_PRETTY_PRINT);
            exit;
        }

        $locId = get_ghl_location_id();
        if (!$locId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing location_id']);
            exit;
        }

        $now    = new DateTimeImmutable();
        $data = [
            'name'        => $name ?: null,
            'phone'       => $phone,
            'email'       => $email ?: null,
            'location_id' => $locId,
            'created_at'  => new \Google\Cloud\Core\Timestamp($now),
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now),
        ];

        $docRef = $db->collection('contacts')->add($data);

        echo json_encode([
            'success' => true,
            'id'      => $docRef->id(),
        ], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to handle contacts request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

