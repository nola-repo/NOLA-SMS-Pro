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
        if (!$locId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing location_id (X-GHL-Location-ID header required)']);
            exit;
        }

        $conversationId = $_GET['id'] ?? $_GET['conversation_id'] ?? null;
        $q = $db->collection('conversations')
                ->where('location_id', '==', $locId);

        if ($conversationId) {
            // Enforce prefixing for lookup if needed
            $prefix = $locId . '_';
            if (str_starts_with($conversationId, 'conv_') && !str_starts_with($conversationId, $prefix)) {
                $conversationId = $prefix . $conversationId;
            }
            $q = $q->where('id', '==', $conversationId);
        }
        
        $q = $q->orderBy('last_message_at', 'DESC');

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

        if (!$id || !$name || !$locId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id, name or location_id']);
            exit;
        }

        // AUTO-SCOPE: Ensure ID is location-prefixed if it's a direct conv
        if (str_starts_with($id, 'conv_')) {
            $prefix = $locId . '_';
            if (!str_starts_with($id, $prefix)) {
                $id = $prefix . $id;
            }
        }

        $docRef = $db->collection('conversations')->document($id);
        $doc = $docRef->snapshot();
        
        if (!$doc->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Conversation not found']);
            exit;
        }

        if ($locId) {
            $updateData[] = ['path' => 'location_id', 'value' => $locId];
        }
        $updateData[] = ['path' => 'id', 'value' => $id];

        $docRef->set(array_column($updateData, 'value', 'path'), ['merge' => true]);

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
        $snap = $docRef->snapshot();
        if ($snap->exists()) {
            if (($snap->data()['location_id'] ?? '') === $locId) {
                $docRef->delete();
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
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

