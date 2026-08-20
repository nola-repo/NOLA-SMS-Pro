<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/../webhook/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/StatusSync.php';
require_once __DIR__ . '/../cache_helper.php';
require_once __DIR__ . '/../performance_logger.php';

NolaPerformance::start('api/messages');


$db = get_firestore();
$config = require __DIR__ . '/../webhook/config.php';
$apiKey = $config['SEMAPHORE_API_KEY'] ?? '';
$apiKeyCache = [];

// Status syncing is now handled by Cloud Scheduler (every 5 min)
// via api/webhook/retrieve_status.php → StatusSync::runSync()

$direction = $_GET['direction'] ?? 'outbound'; // outbound | inbound | all
$conversationId = $_GET['conversation_id'] ?? null; // when set, load messages for one chat (fixes bulk mixing)
$batchId = $_GET['batch_id'] ?? null; // bulk campaign fetch (frontend)
$recipientKey = $_GET['recipient_key'] ?? null; // per-recipient bulk thread fetch (frontend)
$limit = min((int)($_GET['limit'] ?? 50), 100);
$offset = max((int)($_GET['offset'] ?? 0), 0);
$locId = get_ghl_location_id();
$status = $_GET['status'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$locId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing location_id']);
    exit;
}

auth_require_api_or_jwt_for_location($db, (string)$locId);


if ($method === 'PUT') {
    // Rename conversation
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) $body = $_POST;
    $id   = $body['id'] ?? null;
    $name = $body['name'] ?? null;

    if (!$id || !$name) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing id or name']);
        exit;
    }

    $docRef = $db->collection('conversations')->document($id);
    $snap = $docRef->snapshot();
    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Conversation not found']);
        exit;
    }

    // Security: Check ownership
    if (($snap->data()['location_id'] ?? '') !== $locId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Permission denied']);
        exit;
    }

    $updateData = [
        ['path' => 'name',       'value' => $name],
        ['path' => 'location_id', 'value' => $locId],
        ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
    ];

    $docRef->update($updateData);
    NolaCache::deleteRegistry("messages_registry_" . $locId);

    echo json_encode(['success' => true]);
    exit;
}

if ($method === 'DELETE') {
    $conversationId = $_GET['id'] ?? $_GET['conversation_id'] ?? null;

    if (!$conversationId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing conversation_id']);
        exit;
    }

    $docRef = $db->collection('conversations')->document($conversationId);
    $snap = $docRef->snapshot();
    if ($snap->exists()) {
        // Security: Check ownership
        if (($snap->data()['location_id'] ?? '') === $locId) {
            $docRef->delete();
            NolaCache::deleteRegistry("messages_registry_" . $locId);
        } else {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

$out = [
    'success' => true,
    'data' => [],
    'total' => 0,
    'offset' => $offset,
];

$cacheKey = "messages_" . md5((string)$locId . '_' . (string)$direction . '_' . (string)$conversationId . '_' . (string)$batchId . '_' . (string)$recipientKey . '_' . $limit . '_' . $offset . '_' . (string)$status);
$registryKey = "messages_registry_" . (string)$locId;
$forceFresh = isset($_GET['fresh']) || isset($_GET['no_cache']);

if ($method === 'GET' && !$forceFresh) {
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        NolaPerformance::cache('HIT');
        NolaCache::sendApiCacheHeaders(30, true);
        echo json_encode(NolaCache::withCacheMeta($cachedData, 30, true, 'location'), JSON_PRETTY_PRINT);
        exit;
    }
}
NolaPerformance::cache('MISS');

// Strictly map statuses for the UI as requested
$mapStatus = function($s) {
    if (!$s) return null;
    $l = strtolower($s);
    if (in_array($l, ['queued', 'pending', 'sending'])) return 'Sending';
    if (in_array($l, ['sent', 'success', 'delivered'])) return 'Sent';
    if (in_array($l, ['failed', 'expired'])) return 'Failed';
    return ucfirst($l);
};

try {
    // Bulk fetch by batch_id (frontend: /api/messages?batch_id=...).
    if ($batchId !== null && $batchId !== '') {
        $q = $db->collection('messages')
            ->where('location_id', '==', $locId)
            ->where('batch_id', '==', $batchId);

        // Allow combined filtering for a specific contact in a bulk batch
        if ($recipientKey) {
            $q = $q->where('recipient_key', '==', (string)$recipientKey);
        }
        elseif ($conversationId) {
            $q = $q->where('conversation_id', '==', (string)$conversationId);
        }

        $query = $q->orderBy('date_created', 'DESC')
            ->limit($limit)
            ->offset($offset);

        foreach ($query->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $d, $doc->id(), $apiKey, $apiKeyCache);
            $out['data'][] = [
                'id' => $doc->id(),
                'message_id' => $d['message_id'] ?? null,
                'conversation_id' => $d['conversation_id'] ?? null,
                'location_id' => $d['location_id'] ?? null,
                'number' => $d['number'] ?? null,
                'message' => $d['message'] ?? null,
                'direction' => $d['direction'] ?? 'outbound',
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $mapStatus($d['status'] ?? null),
                'batch_id' => $d['batch_id'] ?? null,
                'recipient_key' => $d['recipient_key'] ?? null,
                'date_created' => isset($d['date_created']) ? $d['date_created']->formatAsString() : null,
                'name' => $d['name'] ?? null,
            ];
        }
        $out['total'] = count($out['data']);
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    // Bulk fetch by recipient_key (frontend: /api/messages?recipient_key=...).
    // We map recipient_key -> conversation_id to avoid requiring new composite indexes.
    if ($recipientKey !== null && $recipientKey !== '') {
        $rk = (string)$recipientKey;
        $conv = null;

        $prefix = $locId . '_';
        if (str_starts_with($rk, 'conv_')) {
            $conv = str_starts_with($rk, $prefix) ? $rk : ($prefix . $rk);
        }
        elseif (str_starts_with($rk, 'group-') || str_starts_with($rk, 'group_')) {
            $conv = str_starts_with($rk, $prefix) ? $rk : ($prefix . $rk);
        }
        elseif (str_starts_with($rk, 'batch-') || str_starts_with($rk, 'batch_')) {
            $conv = $prefix . 'group_' . $rk;
        }
        else {
            $digits = preg_replace('/\D+/', '', $rk);
            if (strlen($digits) >= 10) {
                // Normalize and scope
                if (strlen($digits) === 10 && str_starts_with($digits, '9')) $digits = '0' . $digits;
                if (strlen($digits) === 12 && str_starts_with($digits, '639')) $digits = '0' . substr($digits, 2);
                $conv = $prefix . 'conv_' . $digits;
            } else {
                $conv = $prefix . 'group_' . $rk;
            }
        }
 
        $q = $db->collection('messages')
            ->where('location_id', '==', $locId)
            ->where('conversation_id', '==', $conv);

        $query = $q->orderBy('date_created', 'DESC')
            ->limit($limit)
            ->offset($offset);

        foreach ($query->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $d, $doc->id(), $apiKey, $apiKeyCache);
            $out['data'][] = [
                'id' => $doc->id(),
                'message_id' => $d['message_id'] ?? null,
                'conversation_id' => $d['conversation_id'] ?? null,
                'location_id' => $d['location_id'] ?? null,
                'number' => $d['number'] ?? null,
                'message' => $d['message'] ?? null,
                'direction' => $d['direction'] ?? 'outbound',
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $mapStatus($d['status'] ?? null),
                'batch_id' => $d['batch_id'] ?? null,
                'recipient_key' => $d['recipient_key'] ?? null,
                'date_created' => isset($d['date_created']) ? $d['date_created']->formatAsString() : null,
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : (isset($d['date_created']) ? $d['date_created']->formatAsString() : null),
                'name' => $d['name'] ?? null,
            ];
        }
        $out['total'] = count($out['data']);
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    if ($conversationId !== null && $conversationId !== '') {
        $q = $db->collection('messages')
            ->where('conversation_id', '==', $conversationId);

        $recipientKey = $_GET['recipient_key'] ?? null;
        if ($recipientKey) {
            $q = $q->where('recipient_key', '==', $recipientKey);
        }

        $rows = [];
        foreach ($q->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();

            if (!empty($d['location_id']) && $d['location_id'] !== $locId) {
                continue;
            }

            \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $d, $doc->id(), $apiKey, $apiKeyCache);

            $msgDirection = strtolower(trim((string)($d['direction'] ?? 'outbound')));
            $rawStatus = $d['status'] ?? null;
            $msgStatus = $msgDirection === 'inbound' ? 'Received' : $mapStatus($rawStatus);

            $dateCreated = isset($d['date_created']) && is_object($d['date_created']) && method_exists($d['date_created'], 'formatAsString')
                ? $d['date_created']->formatAsString()
                : (isset($d['date_created']) ? (string)$d['date_created'] : null);

            $dateReceived = isset($d['date_received']) && is_object($d['date_received']) && method_exists($d['date_received'], 'formatAsString')
                ? $d['date_received']->formatAsString()
                : (isset($d['date_received']) ? (string)$d['date_received'] : null);

            $createdAt = isset($d['created_at']) && is_object($d['created_at']) && method_exists($d['created_at'], 'formatAsString')
                ? $d['created_at']->formatAsString()
                : (isset($d['created_at']) ? (string)$d['created_at'] : ($dateCreated ?: $dateReceived));

            $timestampStr = isset($d['timestamp']) && is_object($d['timestamp']) && method_exists($d['timestamp'], 'formatAsString')
                ? $d['timestamp']->formatAsString()
                : (isset($d['timestamp']) ? (string)$d['timestamp'] : ($dateReceived ?: ($createdAt ?: $dateCreated)));

            $rows[] = [
                'id' => $doc->id(),
                'message_id' => $d['message_id'] ?? $doc->id(),
                'conversation_id' => $d['conversation_id'] ?? null,
                'location_id' => $d['location_id'] ?? $locId,
                'number' => $d['number'] ?? ($d['to'] ?? ($d['from'] ?? null)),
                'from' => $d['from'] ?? null,
                'to' => $d['to'] ?? null,
                'message' => $d['message'] ?? ($d['text'] ?? ($d['content'] ?? '')),
                'direction' => $msgDirection,
                'sender_id' => $d['sender_id'] ?? null,
                'sender_name' => $d['sender_name'] ?? ($d['sender_id'] ?? null),
                'status' => $msgStatus,
                'batch_id' => $d['batch_id'] ?? null,
                'recipient_key' => $d['recipient_key'] ?? null,
                'date_created' => $dateCreated,
                'date_received' => $dateReceived,
                'created_at' => $createdAt,
                'timestamp' => $timestampStr,
                'name' => $d['name'] ?? null,
                'unisms_virtual_number_id' => $d['unisms_virtual_number_id'] ?? null,
                'unisms_txt_conversation_id' => $d['unisms_txt_conversation_id'] ?? null,
            ];
        }

        // Sort by timestamp DESC
        usort($rows, function($a, $b) {
            $ta = strtotime($a['timestamp'] ?: ($a['date_received'] ?: ($a['created_at'] ?: ($a['date_created'] ?: '0'))));
            $tb = strtotime($b['timestamp'] ?: ($b['date_received'] ?: ($b['created_at'] ?: ($b['date_created'] ?: '0'))));
            return $tb <=> $ta;
        });

        $sliced = array_slice($rows, $offset, $limit);
        $out['data'] = $sliced;
        $out['total'] = count($rows);
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    if ($direction === 'inbound' || $direction === 'all') {
        $q = $db->collection('inbound_messages')
            ->where('location_id', '==', $locId);

        $inboundQuery = $q->orderBy('date_received', 'DESC')
            ->limit($direction === 'all' ? (int)($limit / 2) : $limit)
            ->offset($direction === 'all' ? 0 : $offset);

        foreach ($inboundQuery->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            $out['data'][] = [
                'id' => $doc->id(),
                'direction' => 'inbound',
                'from' => $d['from'] ?? null,
                'message' => $d['message'] ?? null,
                'date_received' => isset($d['date_received']) ? $d['date_received']->formatAsString() : null,
                'message_id' => $d['message_id'] ?? null,
            ];
        }
    }

    if ($direction === 'outbound' || $direction === 'all') {
        $q = $db->collection('sms_logs')
            ->where('location_id', '==', $locId);

        if ($status) {
            $q = $q->where('status', '==', $status);
        }

        $q = $q->orderBy('date_created', 'DESC');

        $fetchLimit = ($direction === 'outbound' ? $limit : (int)($limit / 2));
        $outboundQuery = $q->limit($fetchLimit)
            ->offset($direction === 'outbound' ? $offset : 0);

        $rows = [];
        foreach ($outboundQuery->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            \Nola\Services\StatusSync::checkAndSyncSingleMessage($db, $d, $doc->id(), $apiKey, $apiKeyCache);
            $src = $d['source'] ?? '';
            $type = $d['type'] ?? null;
            if (!$type || $type === 'SMS') {
                if ($src === 'send_sms' || str_contains($d['message'] ?? '', 'Send PH SMS')) {
                    $type = 'Send PH SMS';
                } elseif ($src === 'ghl_provider') {
                    $type = 'Conversation Provider';
                } else {
                    $type = 'SMS';
                }
            }

            $rows[] = [
                'id' => $doc->id(),
                'direction' => 'outbound',
                'type' => $type,
                'message_id' => $d['message_id'] ?? null,
                'numbers' => $d['numbers'] ?? [],
                'message' => $d['message'] ?? null,
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $mapStatus($d['status'] ?? null),
                'date_created' => isset($d['date_created']) ? $d['date_created']->formatAsString() : null,
                'source' => $d['source'] ?? null,
            ];
        }
        $out['data'] = array_merge($out['data'], $rows);
    }

    if ($direction === 'all') {
        usort($out['data'], function ($a, $b) {
            $da = $a['date_created'] ?? $a['date_received'] ?? '';
            $db = $b['date_created'] ?? $b['date_received'] ?? '';
            return strcmp($db, $da);
        });
        $out['data'] = array_slice($out['data'], 0, $limit);
    }

    $out['total'] = count($out['data']);

}
catch (\Throwable $e) {
    http_response_code(500);
    $out = [
        'success' => false,
        'error' => 'Failed to fetch messages',
        'message' => $e->getMessage(),
    ];
}

if ($method === 'GET' && isset($out['success']) && $out['success'] === true) {
    NolaCache::setWithRegistry($registryKey, $cacheKey, $out, 30);
    NolaCache::sendApiCacheHeaders(30, false);
    echo json_encode(NolaCache::withCacheMeta($out, 30, false, 'location'), JSON_PRETTY_PRINT);
    exit;
}

echo json_encode($out, JSON_PRETTY_PRINT);
