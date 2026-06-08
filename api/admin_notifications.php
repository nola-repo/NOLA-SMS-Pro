<?php
/**
 * api/admin_notifications.php
 *
 * Query, read, and manage notifications inside the Admin Portal.
 * Guarded using require_admin_auth().
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';

// ─── JWT Auth Guard ───────────────────────────────────────────────────────────
function require_admin_auth(): array {
    // 1. Try legacy admin headers first
    $adminAuth = $_SERVER['HTTP_X_ADMIN_AUTH'] ?? '';
    $adminUser = $_SERVER['HTTP_X_ADMIN_USER'] ?? '';
    if (!$adminAuth || !$adminUser) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'X-Admin-Auth') === 0) {
                $adminAuth = $value;
            }
            if (strcasecmp($key, 'X-Admin-User') === 0) {
                $adminUser = $value;
            }
        }
    }

    if (strtolower(trim((string)$adminAuth)) === 'true' && !empty($adminUser)) {
        return [
            'username' => $adminUser,
            'role' => 'super_admin'
        ];
    }

    // 2. Fallback to Bearer token
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Admin token missing. Please log in again.']);
        exit;
    }

    $token  = substr($authHeader, 7);
    $secret = getenv('JWT_SECRET') ?: 'nola-super-admin-secret';

    // Verify token validity specifically to return descriptive errors
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Admin token invalid. Please log in again.']);
        exit;
    }

    [$headerB64, $bodyB64, $sigB64] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$headerB64.$bodyB64", $secret, true));
    if (!hash_equals($expected, $sigB64)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Admin token invalid. Please log in again.']);
        exit;
    }

    $payload = json_decode(base64url_decode($bodyB64), true);
    if (!is_array($payload)) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Admin token invalid. Please log in again.']);
        exit;
    }

    if (isset($payload['exp']) && $payload['exp'] < time()) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Admin token expired. Please log in again.']);
        exit;
    }

    return $payload;
}

// ─── Helper: format Firestore timestamp ──────────────────────────────────────
function format_ts($ts): ?string {
    if ($ts === null) return null;
    if (is_object($ts) && method_exists($ts, 'get')) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    return null;
}

// ─── Main Logic ──────────────────────────────────────────────────────────────
$claims = require_admin_auth();
$db = get_firestore();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        $notificationsRef = $db->collection('admin_notifications');
        $query = $notificationsRef->orderBy('created_at', 'DESC');
        
        $unreadOnly = $_GET['unread_only'] ?? $_GET['unreadOnly'] ?? null;
        if ($unreadOnly === 'true' || $unreadOnly === true || $unreadOnly === '1') {
            $query = $query->where('read', '=', false);
        }
        
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;
        
        $query = $query->limit($limit);
        $docs = $query->documents();
        
        $data = [];
        foreach ($docs as $doc) {
            if ($doc->exists()) {
                $d = $doc->data();
                $data[] = [
                    'id' => $doc->id(),
                    'type' => $d['type'] ?? '',
                    'location_id' => $d['location_id'] ?? '',
                    'location_name' => $d['location_name'] ?? '',
                    'email' => $d['email'] ?? '',
                    'balance' => isset($d['balance']) ? (int)$d['balance'] : 0,
                    'threshold' => isset($d['threshold']) ? (int)$d['threshold'] : 0,
                    'created_at' => format_ts($d['created_at'] ?? null),
                    'read' => (bool)($d['read'] ?? false),
                ];
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'data' => $data
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} elseif ($method === 'POST') {
    try {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $action = $input['action'] ?? '';
        
        if ($action === 'mark_read') {
            $notifId = $input['notification_id'] ?? $input['notificationId'] ?? '';
            if (empty($notifId)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'notification_id is required']);
                exit;
            }
            $docRef = $db->collection('admin_notifications')->document($notifId);
            if (!$docRef->snapshot()->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => 'Notification not found']);
                exit;
            }
            $docRef->set(['read' => true], ['merge' => true]);
            echo json_encode(['status' => 'success', 'message' => 'Notification updated successfully']);
        } elseif ($action === 'mark_all_read') {
            $unreadDocs = $db->collection('admin_notifications')->where('read', '=', false)->documents();
            $batch = $db->batch();
            $count = 0;
            foreach ($unreadDocs as $doc) {
                if ($doc->exists()) {
                    $batch->set($doc->reference(), ['read' => true], ['merge' => true]);
                    $count++;
                }
            }
            if ($count > 0) {
                $batch->commit();
            }
            echo json_encode(['status' => 'success', 'message' => 'Notification updated successfully']);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
        }
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
}
