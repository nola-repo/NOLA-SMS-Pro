<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db = get_firestore();

$direction = $_GET['direction'] ?? 'outbound'; // outbound | inbound | all
$conversationId = $_GET['conversation_id'] ?? null; // when set, load messages for one chat (fixes bulk mixing)
$batchId = $_GET['batch_id'] ?? null; // bulk campaign fetch (frontend)
$recipientKey = $_GET['recipient_key'] ?? null; // per-recipient bulk thread fetch (frontend)
$limit = min((int)($_GET['limit'] ?? 50), 100);
$offset = max((int)($_GET['offset'] ?? 0), 0);
$locId = get_ghl_location_id();
$status = $_GET['status'] ?? null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    if (!$locId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing location_id']);
        exit;
    }
}

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
    'limit' => $limit,
    'offset' => $offset,
];

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
            $out['data'][] = [
                'id' => $doc->id(),
                'conversation_id' => $d['conversation_id'] ?? null,
                'number' => $d['number'] ?? null,
                'message' => $d['message'] ?? null,
                'direction' => $d['direction'] ?? 'outbound',
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $d['status'] ?? null,
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
        elseif (str_starts_with($rk, 'group_')) {
            $conv = $rk;
        }
        elseif (str_starts_with($rk, 'batch-') || str_starts_with($rk, 'batch_')) {
            $conv = 'group_' . $rk;
        }
        else {
            $digits = preg_replace('/\D+/', '', $rk);
            if (strlen($digits) >= 10) {
                // Normalize and scope
                if (strlen($digits) === 10 && str_starts_with($digits, '9')) $digits = '0' . $digits;
                if (strlen($digits) === 12 && str_starts_with($digits, '639')) $digits = '0' . substr($digits, 2);
                $conv = $prefix . 'conv_' . $digits;
            } else {
                $conv = 'group_' . $rk;
            }
        }
 
        $q = $db->collection('messages')
            ->where('location_id', '==', $locId)
            ->where('conversation_id', '==', $conv);

        $query = $q->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset);

        foreach ($query->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            $out['data'][] = [
                'id' => $doc->id(),
                'conversation_id' => $d['conversation_id'] ?? null,
                'number' => $d['number'] ?? null,
                'message' => $d['message'] ?? null,
                'direction' => $d['direction'] ?? 'outbound',
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $d['status'] ?? null,
                'batch_id' => $d['batch_id'] ?? null,
                'recipient_key' => $d['recipient_key'] ?? null,
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'name' => $d['name'] ?? null,
            ];
        }
        $out['total'] = count($out['data']);
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    if ($conversationId !== null && $conversationId !== '') {
        $q = $db->collection('messages')
            ->where('location_id', '==', $locId)
            ->where('conversation_id', '==', $conversationId);

        $query = $q->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset);
        foreach ($query->documents() as $doc) {
            if (!$doc->exists())
                continue;
            $d = $doc->data();
            $out['data'][] = [
                'id' => $doc->id(),
                'conversation_id' => $d['conversation_id'] ?? null,
                'number' => $d['number'] ?? null,
                'message' => $d['message'] ?? null,
                'direction' => $d['direction'] ?? 'outbound',
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $d['status'] ?? null,
                'batch_id' => $d['batch_id'] ?? null,
                'recipient_key' => $d['recipient_key'] ?? null,
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'name' => $d['name'] ?? null,
            ];
        }
        $out['total'] = count($out['data']);
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
            $rows[] = [
                'id' => $doc->id(),
                'direction' => 'outbound',
                'message_id' => $d['message_id'] ?? null,
                'numbers' => $d['numbers'] ?? [],
                'message' => $d['message'] ?? null,
                'sender_id' => $d['sender_id'] ?? null,
                'status' => $d['status'] ?? null,
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

echo json_encode($out, JSON_PRETTY_PRINT);
