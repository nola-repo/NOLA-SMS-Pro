<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db = get_firestore();

$direction       = $_GET['direction'] ?? 'outbound'; // outbound | inbound | all
$conversationId  = $_GET['conversation_id'] ?? null; // when set, load messages for one chat (fixes bulk mixing)
$limit           = min((int)($_GET['limit'] ?? 50), 100);
$offset          = max((int)($_GET['offset'] ?? 0), 0);
$status          = $_GET['status'] ?? null;

$out = [
    'success' => true,
    'data'    => [],
    'total'   => 0,
    'limit'   => $limit,
    'offset'  => $offset,
];

try {
    // Load by conversation (sidebar chat): messages where conversation_id == selectedChat, orderBy created_at
    if ($conversationId !== null && $conversationId !== '') {
        $query = $db->collection('messages')
            ->where('conversation_id', '==', $conversationId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->offset($offset);
        foreach ($query->documents() as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $out['data'][] = [
                'id'               => $doc->id(),
                'conversation_id'  => $d['conversation_id'] ?? null,
                'number'           => $d['number'] ?? null,
                'message'          => $d['message'] ?? null,
                'direction'        => $d['direction'] ?? 'outbound',
                'sender_id'        => $d['sender_id'] ?? null,
                'status'           => $d['status'] ?? null,
                'batch_id'         => $d['batch_id'] ?? null,
                'created_at'       => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'name'             => $d['name'] ?? null,
            ];
        }
        $out['total'] = count($out['data']);
        echo json_encode($out, JSON_PRETTY_PRINT);
        exit;
    }

    if ($direction === 'inbound' || $direction === 'all') {
        $inboundQuery = $db->collection('inbound_messages')
            ->orderBy('date_received', 'DESC')
            ->limit($direction === 'all' ? (int)($limit / 2) : $limit)
            ->offset($direction === 'all' ? 0 : $offset);

        foreach ($inboundQuery->documents() as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $out['data'][] = [
                'id'            => $doc->id(),
                'direction'     => 'inbound',
                'from'          => $d['from'] ?? null,
                'message'       => $d['message'] ?? null,
                'date_received' => isset($d['date_received']) ? $d['date_received']->formatAsString() : null,
                'message_id'    => $d['message_id'] ?? null,
            ];
        }
    }

    if ($direction === 'outbound' || $direction === 'all') {
        $fetchLimit = $status ? min($limit * 3, 150) : ($direction === 'outbound' ? $limit : (int)($limit / 2));
        $outboundQuery = $db->collection('sms_logs')
            ->orderBy('date_created', 'DESC')
            ->limit($fetchLimit)
            ->offset($direction === 'outbound' ? $offset : 0);

        $rows = [];
        foreach ($outboundQuery->documents() as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $row = [
                'id'           => $doc->id(),
                'direction'    => 'outbound',
                'message_id'   => $d['message_id'] ?? null,
                'numbers'      => $d['numbers'] ?? [],
                'message'      => $d['message'] ?? null,
                'sender_id'    => $d['sender_id'] ?? null,
                'status'       => $d['status'] ?? null,
                'date_created' => isset($d['date_created']) ? $d['date_created']->formatAsString() : null,
                'source'       => $d['source'] ?? null,
            ];
            if ($status && ($row['status'] ?? '') !== $status) continue;
            $rows[] = $row;
        }
        $out['data'] = array_merge($out['data'], array_slice($rows, 0, $direction === 'outbound' ? $limit : (int)($limit / 2)));
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

} catch (\Throwable $e) {
    http_response_code(500);
    $out = [
        'success' => false,
        'error'   => 'Failed to fetch messages',
        'message' => $e->getMessage(),
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT);
