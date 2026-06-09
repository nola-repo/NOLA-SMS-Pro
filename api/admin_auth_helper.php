<?php

require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/webhook/firestore_client.php';

function admin_auth_json(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function admin_auth_bearer_token(): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? '';

    if (!$authHeader) {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, 'Authorization') === 0) {
                $authHeader = (string) $value;
                break;
            }
        }
    }

    if (!$authHeader || !preg_match('/^Bearer\s+(.+)$/i', trim((string) $authHeader), $m)) {
        admin_auth_json(401, 'Admin token missing. Please log in again.');
    }

    return trim((string) $m[1]);
}

function require_secure_admin_auth(array $allowedRoles = ['super_admin', 'support', 'viewer']): array
{
    $secret = getenv('JWT_SECRET');
    if ($secret === false || trim((string) $secret) === '') {
        admin_auth_json(500, 'Server misconfiguration: JWT secret missing.');
    }

    $claims = jwt_verify(admin_auth_bearer_token(), (string) $secret);
    if (!$claims) {
        admin_auth_json(401, 'Admin token invalid or expired. Please log in again.');
    }

    $role = (string) ($claims['role'] ?? '');
    if (!in_array($role, $allowedRoles, true)) {
        admin_auth_json(403, 'Forbidden: insufficient admin permissions.');
    }

    $email = strtolower(trim((string) ($claims['email'] ?? $claims['username'] ?? '')));
    if ($email === '') {
        admin_auth_json(401, 'Admin token invalid. Please log in again.');
    }

    try {
        $db = get_firestore();
        $adminRef = $db->collection('admins')->document($email);
        $snap = $adminRef->snapshot();

        if (!$snap->exists()) {
            $matches = $db->collection('admins')
                ->where('email', '=', $email)
                ->limit(1)
                ->documents();

            foreach ($matches as $doc) {
                if ($doc->exists()) {
                    $snap = $doc;
                    break;
                }
            }
        }

        if (!$snap->exists() || empty($snap->data()['active'])) {
            admin_auth_json(403, 'Admin account is inactive or no longer exists.');
        }
    } catch (Throwable $e) {
        error_log('[admin_auth_helper] Firestore admin validation failed: ' . $e->getMessage());
        admin_auth_json(500, 'Admin authentication failed.');
    }

    return $claims;
}

if (!function_exists('require_admin_auth')) {
    function require_admin_auth(array $allowedRoles = ['super_admin', 'support', 'viewer']): array
    {
        return require_secure_admin_auth($allowedRoles);
    }
}
