<?php
require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

$method = $_SERVER['REQUEST_METHOD'];
$db = get_firestore();

if ($method === 'GET') {
    // List Admin Users
    try {
        $adminsRef = $db->collection('admins');
        $query = $adminsRef->documents();
        $admins = [];
        foreach ($query as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $created_at = $data['created_at'] ?? null;
                $last_login = $data['last_login'] ?? null;
                
                if (is_object($created_at) && method_exists($created_at, 'get')) {
                    $created_at = $created_at->get()->format(\DateTime::ATOM);
                }
                if (is_object($last_login) && method_exists($last_login, 'get')) {
                    $last_login = $last_login->get()->format(\DateTime::ATOM);
                }

                $admins[] = [
                    'username' => $doc->id(),
                    'role' => $data['role'] ?? 'admin',
                    'active' => $data['active'] ?? false,
                    'created_at' => $created_at,
                    'last_login' => $last_login
                ];
            }
        }
        echo json_encode(['status' => 'success', 'data' => $admins]);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Failed to fetch users: ' . $e->getMessage()]);
        exit;
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $username = $input['username'] ?? '';

    if (empty($action) || empty($username)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Action and username are required']);
        exit;
    }

    $adminRef = $db->collection('admins')->document($username);

    try {
        if ($action === 'create') {
            $password = $input['password'] ?? '';
            $role = $input['role'] ?? 'support';
            if (empty($password)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Password is required for creation']);
                exit;
            }
            if ($adminRef->snapshot()->exists()) {
                http_response_code(409);
                echo json_encode(['status' => 'error', 'message' => 'User already exists']);
                exit;
            }
            $adminRef->set([
                'username' => $username,
                'role' => $role,
                'active' => true,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
                'created_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
                'last_login' => null
            ]);
            echo json_encode(['status' => 'success', 'message' => 'Admin user created.']);
            exit;
        } elseif ($action === 'reset_password') {
            $new_password = $input['new_password'] ?? '';
            if (empty($new_password)) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'New password is required']);
                exit;
            }
            $adminRef->set([
                'password_hash' => password_hash($new_password, PASSWORD_BCRYPT)
            ], ['merge' => true]);
            echo json_encode(['status' => 'success', 'message' => 'Password reset successfully.']);
            exit;
        } elseif ($action === 'toggle_status') {
            $active = isset($input['active']) ? (bool)$input['active'] : false;
            $adminRef->set([
                'active' => $active
            ], ['merge' => true]);
            echo json_encode(['status' => 'success', 'message' => 'Status updated.']);
            exit;
        } elseif ($action === 'record_login') {
            $adminRef->set([
                'last_login' => new \Google\Cloud\Core\Timestamp(new \DateTime())
            ], ['merge' => true]);
        } else {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
            exit;
        }

        echo json_encode(['status' => 'success', 'message' => 'Action completed.']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
} elseif ($method === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = $input['username'] ?? '';

    if (empty($username)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'Username is required']);
        exit;
    }

    try {
        $adminRef = $db->collection('admins')->document($username);
        $snapshot = $adminRef->snapshot();
        
        if (!$snapshot->exists()) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User not found']);
            exit;
        }

        $data = $snapshot->data();
        if (($data['role'] ?? '') === 'super_admin') {
            // Ensure not the last super_admin
            $query = $db->collection('admins')->where('role', '=', 'super_admin')->documents();
            $superAdminsCount = count($query->rows());
            if ($superAdminsCount <= 1) {
                http_response_code(403);
                echo json_encode(['status' => 'error', 'message' => 'Cannot delete the last super_admin']);
                exit;
            }
        }

        $adminRef->delete();
        echo json_encode(['status' => 'success', 'message' => 'Admin deleted.']);
        exit;
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}
