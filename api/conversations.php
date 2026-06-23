<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/services/ApiValueFormatter.php';


$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $locId = get_ghl_location_id();
    if (!$locId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing location_id (X-GHL-Location-ID header or query param required)']);
        exit;
    }

    auth_require_api_or_jwt_for_location($db, (string)$locId);

    if ($method === 'GET') {
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $type = $_GET['type'] ?? null; // optional: direct | bulk

        $conversationId = $_GET['id'] ?? $_GET['conversation_id'] ?? null;

        // Try to load cached conversations list first
        require_once __DIR__ . '/cache_helper.php';
        $cacheTtl = 120;
        $paramsHash = md5(serialize([$limit, $offset, $type, $conversationId]));
        $cacheKey = "conversations_list_{$locId}_{$paramsHash}";
        $registryKey = "conversations_registry_{$locId}";
        $bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);

        $cachedData = !$bypassCache ? NolaCache::get($cacheKey) : null;
        if ($cachedData !== null) {
            NolaCache::sendApiCacheHeaders($cacheTtl, true);
            $responsePayload = [
                'success' => true,
                'data' => $cachedData,
                'limit' => $limit,
                'offset' => $offset,
            ];
            echo json_encode(NolaCache::withCacheMeta($responsePayload, $cacheTtl, true, 'location'), JSON_PRETTY_PRINT);
            exit;
        }

        $q = $db->collection('conversations')
            ->where('location_id', '==', $locId);

        if ($conversationId) {
            // Enforce prefixing for lookup if needed
            $prefix = $locId . '_';
            if ((str_starts_with($conversationId, 'conv_') || str_starts_with($conversationId, 'group_')) && !str_starts_with($conversationId, $prefix)) {
                $conversationId = $prefix . $conversationId;
            }
            $q = $q->where('id', '==', $conversationId);
        }

        $q = $q->orderBy('last_message_at', 'DESC');

        $query = $q->limit($limit)
            ->offset($offset);

        $rows = [];
        foreach ($query->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();

            $row = [
                'id' => $doc->id(),
                'location_id' => $d['location_id'] ?? null,
                'type' => $d['type'] ?? null,
                'members' => $d['members'] ?? [],
                'name' => $d['name'] ?? null,
                'last_message' => $d['last_message'] ?? null,
                'last_message_at' => ApiValueFormatter::timestamp($d['last_message_at'] ?? null),
                'updated_at' => ApiValueFormatter::timestamp($d['updated_at'] ?? null),
                'ghl_contact_id' => $d['ghl_contact_id'] ?? null,
            ];

            if ($type && ($row['type'] ?? '') !== $type) {
                continue;
            }

            $rows[] = $row;
        }

        // Store rows in Cache with Registry mapping
        NolaCache::setWithRegistry($registryKey, $cacheKey, $rows, $cacheTtl);

        $responsePayload = [
            'success' => true,
            'data' => $rows,
            'limit' => $limit,
            'offset' => $offset,
        ];
        NolaCache::sendApiCacheHeaders($cacheTtl, $bypassCache ? 'BYPASS' : false);
        echo json_encode(NolaCache::withCacheMeta($responsePayload, $cacheTtl, $bypassCache ? 'BYPASS' : false, 'location'), JSON_PRETTY_PRINT);
    }
    elseif ($method === 'POST' || $method === 'PUT') {
        // Update conversation name
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!$payload)
            $payload = $_POST;

        $id = $payload['id'] ?? $_GET['id'] ?? null;
        $name = $payload['name'] ?? $_GET['name'] ?? null;

        if (!$id || !$name || !$locId) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id, name or location_id']);
            exit;
        }

        // AUTO-SCOPE: Ensure direct and group conversation IDs are location-prefixed.
        if (str_starts_with($id, 'conv_') || str_starts_with($id, 'group_')) {
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

        if ((string)($doc->data()['location_id'] ?? '') !== (string)$locId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $updateData = [
            ['path' => 'name', 'value' => $name],
            ['path' => 'location_id', 'value' => $locId],
            ['path' => 'id', 'value' => $id],
            ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
        ];

        $docRef->set(array_column($updateData, 'value', 'path'), ['merge' => true]);

        // Invalidate conversations cache for this location ID
        try {
            require_once __DIR__ . '/cache_helper.php';
            NolaCache::deleteRegistry("conversations_registry_{$locId}");
        } catch (\Throwable $cacheEx) {
            error_log("[conversations] Cache invalidation failed: " . $cacheEx->getMessage());
        }

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

                // Optional: Cascade delete messages sharing this ID
                $messages = $db->collection('messages')->where('conversation_id', '==', $id)->documents();
                foreach ($messages as $msgDoc) {
                    $msgDoc->reference()->delete();
                }

                // Invalidate conversations cache for this location ID
                try {
                    require_once __DIR__ . '/cache_helper.php';
                    NolaCache::deleteRegistry("conversations_registry_{$locId}");
                } catch (\Throwable $cacheEx) {
                    error_log("[conversations] Cache invalidation failed: " . $cacheEx->getMessage());
                }

                echo json_encode(['success' => true, 'message' => "Deleted $id"]);
                exit;
            }
            else {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Permission denied']);
                exit;
            }
        }
        else {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => "Conversation $id not found"]);
            exit;
        }
    }
    else {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error' => 'Method not allowed',
        ], JSON_PRETTY_PRINT);
    }
}
catch (\Throwable $e) {
    error_log('[conversations] Failed to process request: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to process request',
    ], JSON_PRETTY_PRINT);
}
