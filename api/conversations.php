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

try {
    if ($method === 'GET') {
        $limit  = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $type   = $_GET['type'] ?? null; // optional: direct | bulk

        $locId = get_ghl_location_id();
        
        $q = $db->collection('conversations')
            ->orderBy('last_message_at', 'DESC');

        if ($locId) {
            $q = $q->where('location_id', '==', $locId);
        }

        $query = $q->limit($limit)
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
    } 
    elseif ($method === 'POST' || $method === 'PUT') {
        // Update conversation name
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload) $payload = $_POST;

        $id = $payload['id'] ?? $_GET['id'] ?? null;
        $name = $payload['name'] ?? $_GET['name'] ?? null;

        if (!$id || !$name) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id or name']);
            exit;
        }

        $docRef = $db->collection('conversations')->document($id);
        $doc = $docRef->snapshot();
        
        if (!$doc->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        $locId = get_ghl_location_id();
        $updateData = [
            ['path' => 'name', 'value' => $name],
            ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
        ];

        if ($locId) {
            $updateData[] = ['path' => 'location_id', 'value' => $locId];
        }

        $docRef->update($updateData);

        echo json_encode(['success' => true, 'message' => 'Conversation updated']);
    }
    elseif ($method === 'DELETE') {
        // Delete a conversation (and optionally its messages)
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id parameter']);
            exit;
        }

        $docRef = $db->collection('conversations')->document($id);
        
        if ($docRef->snapshot()->exists()) {
            $docRef->delete();
        }

        echo json_encode(['success' => true, 'message' => 'Conversation deleted']);
    }
    else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Method not allowed',
        ], JSON_PRETTY_PRINT);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to process request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}

