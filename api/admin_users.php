<?php
/**
 * api/admin_users.php
 *
 * Admin User Management API
 * Manages the `admins` Firestore collection.
 *
 * Endpoints:
 *   GET    /api/admin_users.php            - List all admins
 *   POST   /api/admin_users.php            - Create / Update / Reset password / Toggle status
 *   DELETE /api/admin_users.php            - Delete admin (guards last super_admin)
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/cache_helper.php';

// ─── JWT Auth Guard ───────────────────────────────────────────────────────────
function require_admin_auth(): array {
    return require_secure_admin_auth(['super_admin']);

    // Apache may pass the header as REDIRECT_HTTP_AUTHORIZATION when RewriteRule is active
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? '';
    if (!str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: missing token']);
        exit;
    }

    $token  = substr($authHeader, 7);
    $secret = getenv('JWT_SECRET') ?: '';
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
function admin_email_from_input(array $input): string {
    return strtolower(trim($input['email'] ?? $input['username'] ?? ''));
}

function admin_doc_for_email($db, string $email): array {
    $docRef = $db->collection('admins')->document($email);
    $snap = $docRef->snapshot();

    if ($snap->exists()) {
        return [$docRef, $snap];
    }

    $matches = $db->collection('admins')
        ->where('email', '=', $email)
        ->limit(1)
        ->documents();

    foreach ($matches as $doc) {
        if ($doc->exists()) {
            $legacyRef = $db->collection('admins')->document($doc->id());
            return [$legacyRef, $doc];
        }
    }

    return [$docRef, $snap];
}

$claims = require_secure_admin_auth(['super_admin']);
$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: List All Admins ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $cacheKey = "admin_admins_list";
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        echo json_encode($cachedData);
        exit;
    }

    try {
        $snapshot = $db->collection('admins')->documents();
        $admins   = [];

        foreach ($snapshot as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();

            $adminEmail = strtolower(trim((string)($d['email'] ?? $doc->id())));

            $admins[] = [
                'email'      => $adminEmail,
                'username'   => $doc->id(),
                'role'       => $d['role']       ?? 'viewer',
                'name'       => $d['name']       ?? $d['full_name'] ?? '',
                'full_name'  => $d['full_name']  ?? $d['name'] ?? '',
                'phone'      => $d['phone']      ?? $d['phone_number'] ?? '',
                'phone_number' => $d['phone_number'] ?? $d['phone'] ?? '',
                'active'     => (bool)($d['active'] ?? false),
                'created_at' => format_ts($d['created_at'] ?? null),
                'last_login' => format_ts($d['last_login']  ?? null),
            ];
        }

        // Sort by email for a stable list
        usort($admins, fn($a, $b) => strcmp($a['email'], $b['email']));

        $responsePayload = ['status' => 'success', 'data' => $admins];
        NolaCache::set($cacheKey, $responsePayload, 300); // 5 minutes cache
        echo json_encode($responsePayload);
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
        $email    = admin_email_from_input($input);
        $password = $input['password']      ?? '';
        $role     = $input['role']          ?? 'viewer';
        $name     = trim((string)($input['name'] ?? $input['full_name'] ?? ''));
        $phone    = trim((string)($input['phone'] ?? $input['phone_number'] ?? ''));

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'email and password are required']);
            exit;
        }

        $allowed_roles = ['super_admin', 'support', 'viewer'];
        if (!in_array($role, $allowed_roles, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid role. Must be: ' . implode(', ', $allowed_roles)]);
            exit;
        }

        try {
            [$docRef, $snap] = admin_doc_for_email($db, $email);

            if ($snap->exists()) {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' already exists"]);
                exit;
            }

            $docRef->set([
                'email'           => $email,
                'role'            => $role,
                'name'            => $name,
                'full_name'       => $name,
                'phone'           => $phone,
                'phone_number'    => $phone,
                'active'          => true,
                'hashed_password' => password_hash($password, PASSWORD_BCRYPT),
                'created_at'      => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                'last_login'      => null,
            ]);

            NolaCache::invalidateAdminDashboard();

            echo json_encode(['status' => 'success', 'message' => "Admin '{$email}' created successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Reset Password ────────────────────────────────────────────────────────
    if ($action === 'update') {
        $email    = admin_email_from_input($input);
        $newEmail = strtolower(trim((string)($input['new_email'] ?? $input['username'] ?? $email)));
        $name     = trim((string)($input['name'] ?? $input['full_name'] ?? ''));
        $phone    = trim((string)($input['phone'] ?? $input['phone_number'] ?? ''));
        $role     = $input['role'] ?? 'viewer';

        if (empty($email) || empty($newEmail) || empty($name)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'email, new_email, and name are required']);
            exit;
        }

        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid email address']);
            exit;
        }

        $allowed_roles = ['super_admin', 'support', 'viewer'];
        if (!in_array($role, $allowed_roles, true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid role. Must be: ' . implode(', ', $allowed_roles)]);
            exit;
        }

        try {
            [$docRef, $snap] = admin_doc_for_email($db, $email);

            if (!$snap->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' not found"]);
                exit;
            }

            $existing = $snap->data();
            $currentRole = $existing['role'] ?? '';
            if ($currentRole === 'super_admin' && $role !== 'super_admin') {
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
                        'message' => 'Cannot change the role of the last super_admin account',
                    ]);
                    exit;
                }
            }

            $payload = $existing;
            $payload['email'] = $newEmail;
            $payload['role'] = $role;
            $payload['name'] = $name;
            $payload['full_name'] = $name;
            $payload['phone'] = $phone;
            $payload['phone_number'] = $phone;

            if ($newEmail !== $email) {
                [$newDocRef, $newSnap] = admin_doc_for_email($db, $newEmail);
                if ($newSnap->exists()) {
                    http_response_code(409);
                    echo json_encode(['status' => 'error', 'message' => "Admin '{$newEmail}' already exists"]);
                    exit;
                }

                $newDocRef->set($payload);
                $docRef->delete();
            } else {
                $docRef->update([
                    ['path' => 'email', 'value' => $newEmail],
                    ['path' => 'role', 'value' => $role],
                    ['path' => 'name', 'value' => $name],
                    ['path' => 'full_name', 'value' => $name],
                    ['path' => 'phone', 'value' => $phone],
                    ['path' => 'phone_number', 'value' => $phone],
                ]);
            }

            NolaCache::invalidateAdminDashboard();

            echo json_encode(['status' => 'success', 'message' => "Admin '{$newEmail}' updated successfully"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }
    if ($action === 'reset_password') {
        $email        = admin_email_from_input($input);
        $new_password = $input['new_password'] ?? '';

        if (empty($email) || empty($new_password)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'email and new_password are required']);
            exit;
        }

        try {
            [$docRef, $snap] = admin_doc_for_email($db, $email);

            if (!$snap->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' not found"]);
                exit;
            }

            $docRef->update([
                ['path' => 'hashed_password', 'value' => password_hash($new_password, PASSWORD_BCRYPT)],
            ]);

            NolaCache::invalidateAdminDashboard();

            echo json_encode(['status' => 'success', 'message' => "Password reset for '{$email}'"]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        }
        exit;
    }

    // ── Toggle Status ─────────────────────────────────────────────────────────
    if ($action === 'toggle_status') {
        $email    = admin_email_from_input($input);
        $active   = $input['active']        ?? null;

        if (empty($email) || !is_bool($active)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'email and active (boolean) are required']);
            exit;
        }

        try {
            [$docRef, $snap] = admin_doc_for_email($db, $email);

            if (!$snap->exists()) {
                http_response_code(404);
                echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' not found"]);
                exit;
            }

            $docRef->update([
                ['path' => 'active', 'value' => $active],
            ]);

            NolaCache::invalidateAdminDashboard();

            $state = $active ? 'activated' : 'deactivated';
            echo json_encode(['status' => 'success', 'message' => "Admin '{$email}' {$state}"]);
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
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $email = admin_email_from_input($input);

    if (empty($email)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'email is required']);
        exit;
    }

    try {
        [$docRef, $snap] = admin_doc_for_email($db, $email);

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => "Admin '{$email}' not found"]);
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
        NolaCache::invalidateAdminDashboard();
        echo json_encode(['status' => 'success', 'message' => "Admin '{$email}' deleted"]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── Fallback ────────────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
