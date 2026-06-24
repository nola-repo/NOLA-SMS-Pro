<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/services/ApiValueFormatter.php';

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function tickets_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
}

function tickets_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function tickets_now_timestamp(): \Google\Cloud\Core\Timestamp
{
    return new \Google\Cloud\Core\Timestamp(new DateTimeImmutable());
}

function tickets_public_row($doc): array
{
    $d = $doc->data();
    $events = [];
    foreach (($d['events'] ?? []) as $event) {
        if (!is_array($event)) {
            continue;
        }
        $events[] = [
            'type' => $event['type'] ?? '',
            'message' => $event['message'] ?? '',
            'status' => $event['status'] ?? null,
            'created_at' => ApiValueFormatter::timestamp($event['created_at'] ?? null),
            'actor_id' => $event['actor_id'] ?? null,
            'actor_email' => $event['actor_email'] ?? null,
        ];
    }

    return [
        'ticket_id' => $doc->id(),
        'id' => $doc->id(),
        'location_id' => $d['location_id'] ?? null,
        'user_id' => $d['user_id'] ?? null,
        'user_email' => $d['user_email'] ?? null,
        'subject' => $d['subject'] ?? '',
        'message' => $d['message'] ?? '',
        'status' => $d['status'] ?? 'open',
        'priority' => $d['priority'] ?? 'normal',
        'events' => $events,
        'created_at' => ApiValueFormatter::timestamp($d['created_at'] ?? null),
        'updated_at' => ApiValueFormatter::timestamp($d['updated_at'] ?? null),
        'closed_at' => ApiValueFormatter::timestamp($d['closed_at'] ?? null),
    ];
}

function tickets_actor(?array $jwtCtx): array
{
    if ($jwtCtx === null) {
        return ['id' => null, 'email' => null, 'role' => null];
    }

    return [
        'id' => $jwtCtx['uid'] ?? null,
        'email' => $jwtCtx['payload']['email'] ?? ($jwtCtx['profile']['email'] ?? null),
        'role' => $jwtCtx['payload']['role'] ?? ($jwtCtx['profile']['role'] ?? null),
    ];
}

function tickets_cache_registry(string $locId): string
{
    return "tickets_registry_{$locId}";
}

function tickets_invalidate_cache(string $locId): void
{
    try {
        NolaCache::deleteRegistry(tickets_cache_registry($locId));
    } catch (\Throwable $cacheEx) {
        error_log('[tickets] Cache invalidation failed: ' . $cacheEx->getMessage());
    }
}

try {
    $locId = get_ghl_location_id();
    if (!$locId) {
        tickets_json_response(['success' => false, 'error' => 'Missing location_id'], 400);
    }

    $jwtCtx = auth_require_api_or_jwt_for_location($db, (string)$locId);
    $actor = tickets_actor($jwtCtx);

    if ($method === 'GET') {
        $id = trim((string)($_GET['id'] ?? $_GET['ticket_id'] ?? ''));
        $status = trim((string)($_GET['status'] ?? ''));
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);

        if ($id !== '') {
            $snap = $db->collection('support_tickets')->document($id)->snapshot();
            if (!$snap->exists()) {
                tickets_json_response(['success' => false, 'error' => 'Ticket not found'], 404);
            }
            $row = tickets_public_row($snap);
            if ((string)($row['location_id'] ?? '') !== (string)$locId) {
                tickets_json_response(['success' => false, 'error' => 'Permission denied'], 403);
            }
            tickets_json_response(['success' => true, 'data' => $row]);
        }

        $cacheTtl = 60;
        $cacheKey = 'tickets_' . md5(json_encode([$locId, $status, $limit, $offset]));
        $bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);
        if (!$bypassCache) {
            $cached = NolaCache::get($cacheKey);
            if (is_array($cached)) {
                NolaCache::sendApiCacheHeaders($cacheTtl, true);
                echo json_encode(NolaCache::withCacheMeta($cached, $cacheTtl, true, 'location'), JSON_PRETTY_PRINT);
                exit;
            }
        }

        $q = $db->collection('support_tickets')
            ->where('location_id', '==', (string)$locId);
        if ($status !== '' && strtolower($status) !== 'all') {
            $q = $q->where('status', '==', strtolower($status));
        }

        $docs = $q->documents();
        $rows = [];
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $rows[] = tickets_public_row($doc);
            }
        }

        usort($rows, fn($a, $b) => strcmp((string)$b['updated_at'], (string)$a['updated_at']));
        $total = count($rows);
        $rows = array_slice($rows, $offset, $limit);

        $payload = [
            'success' => true,
            'data' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
        NolaCache::setWithRegistry(tickets_cache_registry((string)$locId), $cacheKey, $payload, $cacheTtl);
        NolaCache::sendApiCacheHeaders($cacheTtl, $bypassCache ? 'BYPASS' : false);
        echo json_encode(NolaCache::withCacheMeta($payload, $cacheTtl, $bypassCache ? 'BYPASS' : false, 'location'), JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $body = tickets_json_body();
        $subject = trim((string)($body['subject'] ?? ''));
        $message = trim((string)($body['message'] ?? $body['description'] ?? ''));
        $priority = strtolower(trim((string)($body['priority'] ?? 'normal')));
        $allowedPriorities = ['low', 'normal', 'high', 'urgent'];

        if ($subject === '') {
            tickets_json_response(['success' => false, 'error' => 'subject is required'], 400);
        }
        if ($message === '') {
            tickets_json_response(['success' => false, 'error' => 'message is required'], 400);
        }
        if (!in_array($priority, $allowedPriorities, true)) {
            $priority = 'normal';
        }

        $now = tickets_now_timestamp();
        $ticketRef = $db->collection('support_tickets')->newDocument();
        $event = [
            'type' => 'created',
            'message' => 'Ticket created',
            'status' => 'open',
            'created_at' => $now,
            'actor_id' => $actor['id'],
            'actor_email' => $actor['email'],
        ];

        $payload = [
            'ticket_id' => $ticketRef->id(),
            'location_id' => (string)$locId,
            'user_id' => $actor['id'],
            'user_email' => $actor['email'],
            'subject' => $subject,
            'message' => $message,
            'status' => 'open',
            'priority' => $priority,
            'events' => [$event],
            'created_at' => $now,
            'updated_at' => $now,
            'closed_at' => null,
        ];

        $ticketRef->set($payload);
        tickets_invalidate_cache((string)$locId);

        tickets_json_response([
            'success' => true,
            'message' => 'Ticket submitted',
            'ticket_id' => $ticketRef->id(),
            'data' => tickets_public_row($ticketRef->snapshot()),
        ], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $body = tickets_json_body();
        $id = trim((string)($body['id'] ?? $body['ticket_id'] ?? $_GET['id'] ?? ''));
        if ($id === '') {
            tickets_json_response(['success' => false, 'error' => 'ticket_id is required'], 400);
        }

        $ticketRef = $db->collection('support_tickets')->document($id);
        $snap = $ticketRef->snapshot();
        if (!$snap->exists()) {
            tickets_json_response(['success' => false, 'error' => 'Ticket not found'], 404);
        }
        $data = $snap->data();
        if ((string)($data['location_id'] ?? '') !== (string)$locId) {
            tickets_json_response(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $allowedStatuses = ['open', 'pending', 'resolved', 'closed'];
        $updates = [
            'updated_at' => tickets_now_timestamp(),
        ];
        if (array_key_exists('status', $body)) {
            $status = strtolower(trim((string)$body['status']));
            if (!in_array($status, $allowedStatuses, true)) {
                tickets_json_response(['success' => false, 'error' => 'Invalid ticket status'], 400);
            }
            $updates['status'] = $status;
            if (in_array($status, ['resolved', 'closed'], true)) {
                $updates['closed_at'] = tickets_now_timestamp();
            } else {
                $updates['closed_at'] = null;
            }
        }
        if (array_key_exists('priority', $body)) {
            $priority = strtolower(trim((string)$body['priority']));
            if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true)) {
                tickets_json_response(['success' => false, 'error' => 'Invalid ticket priority'], 400);
            }
            $updates['priority'] = $priority;
        }
        if (array_key_exists('subject', $body)) {
            $subject = trim((string)$body['subject']);
            if ($subject === '') {
                tickets_json_response(['success' => false, 'error' => 'subject cannot be empty'], 400);
            }
            $updates['subject'] = $subject;
        }
        if (array_key_exists('message', $body)) {
            $message = trim((string)$body['message']);
            if ($message === '') {
                tickets_json_response(['success' => false, 'error' => 'message cannot be empty'], 400);
            }
            $updates['message'] = $message;
        }

        $note = trim((string)($body['note'] ?? ''));
        if (count($updates) === 1 && $note === '') {
            tickets_json_response(['success' => false, 'error' => 'No ticket fields provided'], 400);
        }

        $events = $data['events'] ?? [];
        if (!is_array($events)) {
            $events = [];
        }
        $events[] = [
            'type' => 'updated',
            'message' => $note !== '' ? $note : 'Ticket updated',
            'status' => $updates['status'] ?? ($data['status'] ?? 'open'),
            'created_at' => tickets_now_timestamp(),
            'actor_id' => $actor['id'],
            'actor_email' => $actor['email'],
        ];
        $updates['events'] = $events;

        $ticketRef->set($updates, ['merge' => true]);
        tickets_invalidate_cache((string)$locId);

        tickets_json_response([
            'success' => true,
            'message' => 'Ticket updated',
            'data' => tickets_public_row($ticketRef->snapshot()),
        ]);
    }

    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? $_GET['ticket_id'] ?? ''));
        if ($id === '') {
            $body = tickets_json_body();
            $id = trim((string)($body['id'] ?? $body['ticket_id'] ?? ''));
        }
        if ($id === '') {
            tickets_json_response(['success' => false, 'error' => 'ticket_id is required'], 400);
        }

        $ticketRef = $db->collection('support_tickets')->document($id);
        $snap = $ticketRef->snapshot();
        if (!$snap->exists()) {
            tickets_json_response(['success' => false, 'error' => 'Ticket not found'], 404);
        }
        $data = $snap->data();
        if ((string)($data['location_id'] ?? '') !== (string)$locId) {
            tickets_json_response(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $now = tickets_now_timestamp();
        $events = $data['events'] ?? [];
        if (!is_array($events)) {
            $events = [];
        }
        $events[] = [
            'type' => 'closed',
            'message' => 'Ticket closed',
            'status' => 'closed',
            'created_at' => $now,
            'actor_id' => $actor['id'],
            'actor_email' => $actor['email'],
        ];

        $ticketRef->set([
            'status' => 'closed',
            'closed_at' => $now,
            'updated_at' => $now,
            'events' => $events,
        ], ['merge' => true]);
        tickets_invalidate_cache((string)$locId);

        tickets_json_response(['success' => true, 'message' => 'Ticket closed']);
    }

    tickets_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
} catch (\Throwable $e) {
    error_log('[tickets] Failed to process request: ' . $e->getMessage());
    tickets_json_response([
        'success' => false,
        'error' => 'Failed to process tickets request',
        'message' => $e->getMessage(),
    ], 500);
}

