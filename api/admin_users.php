<?php
/**
 * api/admin_users.php
 *
 * Admin User Management API
 * Manages the `admins` Firestore collection.
 *
 * Endpoints:
 *   GET    /api/admin_users.php            - List all admins
 *   POST   /api/admin_users.php            - Create / Reset password / Toggle status
 *   DELETE /api/admin_users.php            - Delete admin (guards last super_admin)
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';

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

// ─── Helper: format Firestore timestamp ──────────────────────────────────────
function format_ts($ts): ?string {
    if ($ts === null) return null;
    // Google\Protobuf\Timestamp or Google\Cloud\Core\Timestamp
    if (is_object($ts) && method_exists($ts, 'get')) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    return null;
}

// ─── Bootstrap ───────────────────────────────────────────────────────────────
$claims = require_admin_auth();
$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: List All Admins ─────────────────────────────────────────────────────
if ($method === 'GET') {
    try {
        $snapshot = $db->collection('admins')->documents();
        $admins   = [];

        foreach ($snapshot as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();

            $admins[] = [
                'username'   => $doc->id(),
                'role'       => $d['role']       ?? 'viewer',
                'active'     => (bool)($d['active'] ?? false),
                'created_at' => format_ts($d['created_at'] ?? null),
                'last_login' => format_ts($d['last_login']  ?? null),
            ];
        }

        // Sort by username for a stable list
        usort($admins, fn($a, $b) => strcmp($a['username'], $b['username']));

        echo json_encode(['status' => 'success', 'data' => $admins]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── POST: Create / Reset Password / Toggle Status ───────────────────────────
if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';

    // ── Create Admin ──────────────────────────────────────────────────────────
    if ($action === 'create') {
        $username = trim($input['username'] ?? '');
        $password = $input['password']      ?? '';
        $role     = $input['role']          ?? 'viewer';

        if (empty($username) || empty($password)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'username and password are required']);
            exit;
        }

        $allowed_roles = ['super_admin', 'support', 'viewer'];
        if (!in_array($role, $allowed_roles, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid role. Must be: ' . implode(', ', $allowed_roles)]);
            exit;
        }

        try {
            $docRef  = $db->collection('admins')->document($username);
            $snap    = $docRef->snapshot();

            if ($snap->exists()) {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$username}' already exists"]);
                exit;
            }

            $docRef->set([
                'username'        => $username,
                'role'            => $role,
                'active'          => true,
                'hashed_password' => password_hash($password, PASSWORD_BCRYPT),
                'created_at'      => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                'last_login'      => null,
            ]);

            echo json_encode(['status' => 'success', 'message' => "Admin '{$username}' created successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Reset Password ────────────────────────────────────────────────────────
    if ($action === 'reset_password') {
        $username     = trim($input['username']     ?? '');
        $new_password = $input['new_password'] ?? '';

        if (empty($username) || empty($new_password)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'username and new_password are required']);
            exit;
        }

        try {
            $docRef = $db->collection('admins')->document($username);
            $snap   = $docRef->snapshot();

            if (!$snap->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$username}' not found"]);
                exit;
            }

            $docRef->update([
                ['path' => 'hashed_password', 'value' => password_hash($new_password, PASSWORD_BCRYPT)],
            ]);

            echo json_encode(['status' => 'success', 'message' => "Password reset for '{$username}'"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Toggle Status ─────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        $username = trim($input['username'] ?? '');
        $active   = $input['active']        ?? null;

        if (empty($username) || !is_bool($active)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'username and active (boolean) are required']);
            exit;
        }

        try {
            $docRef = $db->collection('admins')->document($username);
            $snap   = $docRef->snapshot();

            if (!$snap->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$username}' not found"]);
                exit;
            }

            $docRef->update([
                ['path' => 'active', 'value' => $active],
            ]);

            $state = $active ? 'activated' : 'deactivated';
            echo json_encode(['status' => 'success', 'message' => "Admin '{$username}' {$state}"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // Unknown action
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => "Unknown action: '{$action}'"]);
    exit;
}

// ─── DELETE: Delete Admin ─────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = trim($input['username'] ?? '');

    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'username is required']);
        exit;
    }

    try {
        $docRef = $db->collection('admins')->document($username);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => "Admin '{$username}' not found"]);
            exit;
        }

        $data = $snap->data();
        $role = $data['role'] ?? '';

        // Safety: do not allow deleting the last super_admin
        if ($role === 'super_admin') {
            $superAdmins = $db->collection('admins')
                ->where('role', '=', 'super_admin')
                ->documents();

            $count = 0;
            foreach ($superAdmins as $s) {
                if ($s->exists()) $count++;
            }

            if ($count <= 1) {
                http_response_code(403);
                echo json_encode([
                    'status'  => 'error',
                    'message' => 'Cannot delete the last super_admin account',
                ]);
                exit;
            }
        }

        $docRef->delete();
        echo json_encode(['status' => 'success', 'message' => "Admin '{$username}' deleted"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── Fallback ────────────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
