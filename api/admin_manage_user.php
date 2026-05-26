<?php
/**
 * api/admin_manage_user.php
 *
 * Admin Manage User API
 * Resets or deletes a user subaccount.
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/services/CreditManager.php';

// ─── JWT Auth Guard ───────────────────────────────────────────────────────────
function require_admin_auth(): array {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: missing token']);
        exit;
    }

    $token  = substr($authHeader, 7);
    $secret = getenv('JWT_SECRET') ?: 'nola-super-admin-secret';
    $claims = jwt_verify($token, $secret);

    if (!$claims) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: invalid or expired token']);
        exit;
    }

    return $claims;
}

// ─── Main Logic ──────────────────────────────────────────────────────────────
$claims = require_admin_auth();
$db     = get_firestore();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$input  = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $input['action'] ?? '';
$userId = $input['user_id'] ?? '';

if (empty($userId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
    exit;
}

if (!in_array($action, ['reset', 'delete'], true)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'action must be reset or delete']);
    exit;
}

try {
    $userRef  = $db->collection('users')->document($userId);
    $userSnap = $userRef->snapshot();

    if (!$userSnap->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'User not found']);
        exit;
    }

    $userData = $userSnap->data();

    // ── Reset Subaccount ──────────────────────────────────────────────────────
    if ($action === 'reset') {
        $locId = $userData['active_location_id'] ?? $userData['location_id'] ?? '';

        // 1. Reset user document fields
        $userUpdate = [
            'credit_balance'     => 0,
            'updated_at'         => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ];
        $userRef->set($userUpdate, ['merge' => true]);

        // 2. Reset integration document if location ID exists
        if (!empty($locId)) {
            $intDocId = CreditManager::integration_doc_id_for_location((string)$locId);
            $intRef   = $db->collection('integrations')->document($intDocId);
            $intSnap  = $intRef->snapshot();

            if ($intSnap->exists()) {
                $intRef->set([
                    'credit_balance'     => 0,
                    'free_usage_count'   => 0,
                    'approved_sender_id' => null,
                    'updated_at'         => new \Google\Cloud\Core\Timestamp(new \DateTime())
                ], ['merge' => true]);
            }
        }

        echo json_encode([
            'status'  => 'success',
            'message' => 'Subaccount config and usage reset successfully.'
        ]);
        exit;
    }

    // ── Delete Account ────────────────────────────────────────────────────────
    if ($action === 'delete') {
        $userRef->delete();
        echo json_encode([
            'status'  => 'success',
            'message' => 'User account permanently deleted.'
        ]);
        exit;
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
