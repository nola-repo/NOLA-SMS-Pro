<?php
/**
 * Location-scoped notifications endpoint for frontend builds that call
 * /api/notifications.
 *
 * Admin-wide notification management stays in admin_notifications.php. This
 * compatibility endpoint accepts normal app JWTs and only exposes documents
 * for the caller's authorized GHL location.
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/jwt_helper.php';

function notifications_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function notifications_format_ts($ts): ?string
{
    if ($ts === null) {
        return null;
    }
    if (is_object($ts) && method_exists($ts, 'get')) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if (is_string($ts)) {
        return $ts;
    }

    return null;
}

function notifications_read_input(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode($raw ?: '', true);

    return is_array($input) ? $input : [];
}

function notifications_requested_location_id(array $input = []): string
{
    $candidates = [
        $input['location_id'] ?? null,
        $input['locationId'] ?? null,
        $_GET['location_id'] ?? null,
        $_GET['locationId'] ?? null,
        get_ghl_location_id(),
    ];

    foreach ($candidates as $candidate) {
        $value = trim((string) ($candidate ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function notifications_seed_auth_header_from_fallback(): void
{
    if (auth_extract_bearer_token_optional() !== null) {
        return;
    }

    $fallbacks = [
        $_GET['token'] ?? '',
        $_COOKIE['nola_auth_token'] ?? '',
        $_COOKIE['auth_token'] ?? '',
        $_COOKIE['token'] ?? '',
    ];

    foreach ($fallbacks as $candidate) {
        $token = trim((string) $candidate);
        if ($token === '') {
            continue;
        }
        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        $_SERVER['Authorization'] = 'Bearer ' . $token;
        return;
    }
}

function notifications_profile_location_id(array $jwtCtx): string
{
    $profile = $jwtCtx['profile'] ?? [];
    $candidates = [
        $profile['active_location_id'] ?? null,
        $profile['location_id'] ?? null,
        $profile['ghl_location_id'] ?? null,
    ];

    $ref = trim((string) ($profile['ghl_token_ref'] ?? ''));
    $parsed = $ref !== '' ? auth_parse_ghl_token_ref($ref) : null;
    if ($parsed !== null) {
        $candidates[] = $parsed['id'] ?? null;
    }

    foreach ($candidates as $candidate) {
        $value = trim((string) ($candidate ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function notifications_assert_location_allowed($db, array $jwtCtx, string $locationId): void
{
    if ($locationId === '') {
        notifications_json(400, [
            'status' => 'error',
            'message' => 'Missing location_id',
        ]);
    }

    auth_assert_ghl_api_location_allowed($db, $jwtCtx, $locationId);
}

function notifications_doc_payload($doc, array $d): array
{
    return [
        'id' => $doc->id(),
        'type' => $d['type'] ?? '',
        'location_id' => $d['location_id'] ?? '',
        'location_name' => $d['location_name'] ?? '',
        'email' => $d['email'] ?? '',
        'balance' => array_key_exists('balance', $d) ? (int) $d['balance'] : null,
        'threshold' => array_key_exists('threshold', $d) ? (int) $d['threshold'] : null,
        'created_at' => notifications_format_ts($d['created_at'] ?? null),
        'read' => (bool) ($d['read'] ?? false),
        'metadata' => (isset($d['metadata']) && is_array($d['metadata']))
            ? $d['metadata']
            : new \stdClass(),
    ];
}

$db = get_firestore();
$input = notifications_read_input();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

notifications_seed_auth_header_from_fallback();
$jwtCtx = auth_get_optional_jwt_context($db);
if ($jwtCtx === null) {
    notifications_json(401, [
        'status' => 'error',
        'message' => 'Auth token missing. Please log in again.',
    ]);
}

$locationId = notifications_requested_location_id($input);
if ($locationId === '') {
    $locationId = notifications_profile_location_id($jwtCtx);
}

notifications_assert_location_allowed($db, $jwtCtx, $locationId);

try {
    if ($method === 'GET') {
        $unreadOnly = $_GET['unread_only'] ?? $_GET['unreadOnly'] ?? null;
        $filterUnread = ($unreadOnly === 'true' || $unreadOnly === true || $unreadOnly === '1');

        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        if ($limit <= 0) {
            $limit = 20;
        }
        if ($limit > 100) {
            $limit = 100;
        }

        $docs = $db->collection('admin_notifications')
            ->where('location_id', '=', $locationId)
            ->documents();

        $data = [];
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $d = $doc->data();
            if ($filterUnread && (bool) ($d['read'] ?? false)) {
                continue;
            }
            $data[] = notifications_doc_payload($doc, $d);
        }

        usort($data, static function (array $a, array $b): int {
            return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        });

        echo json_encode([
            'status' => 'success',
            'data' => array_slice($data, 0, $limit),
        ]);
        exit;
    }

    if ($method === 'POST') {
        $action = (string) ($input['action'] ?? '');

        if ($action === 'mark_read') {
            $notifId = trim((string) ($input['notification_id'] ?? $input['notificationId'] ?? ''));
            if ($notifId === '') {
                notifications_json(400, [
                    'status' => 'error',
                    'message' => 'notification_id is required',
                ]);
            }

            $docRef = $db->collection('admin_notifications')->document($notifId);
            $snap = $docRef->snapshot();
            if (!$snap->exists()) {
                notifications_json(404, [
                    'status' => 'error',
                    'message' => 'Notification not found',
                ]);
            }

            $data = $snap->data();
            if ((string) ($data['location_id'] ?? '') !== $locationId) {
                notifications_json(403, [
                    'status' => 'error',
                    'message' => 'Notification does not belong to this location',
                ]);
            }

            $docRef->set(['read' => true], ['merge' => true]);
            echo json_encode([
                'status' => 'success',
                'message' => 'Notification updated successfully',
            ]);
            exit;
        }

        if ($action === 'mark_all_read') {
            $docs = $db->collection('admin_notifications')
                ->where('location_id', '=', $locationId)
                ->documents();

            $batch = $db->batch();
            $count = 0;
            foreach ($docs as $doc) {
                if (!$doc->exists()) {
                    continue;
                }
                if ((bool) (($doc->data())['read'] ?? false)) {
                    continue;
                }
                $batch->set($doc->reference(), ['read' => true], ['merge' => true]);
                $count++;
            }
            if ($count > 0) {
                $batch->commit();
            }

            echo json_encode([
                'status' => 'success',
                'message' => 'Notification updated successfully',
                'count' => $count,
            ]);
            exit;
        }

        notifications_json(400, [
            'status' => 'error',
            'message' => 'Invalid action',
        ]);
    }

    notifications_json(405, [
        'status' => 'error',
        'message' => 'Method not allowed',
    ]);
} catch (\Throwable $e) {
    error_log('[api/notifications.php] ' . $e->getMessage());
    notifications_json(500, [
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
