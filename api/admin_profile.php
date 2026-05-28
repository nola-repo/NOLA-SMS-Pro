<?php
/**
 * api/admin_profile.php
 *
 * Admin Subaccount Profile Read + Update API
 * Writes only to Firestore `users` document.
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
$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'];

// ─── GET: Fetch Single User Profile ──────────────────────────────────────────
if ($method === 'GET') {
    $userId = $_GET['user_id'] ?? '';

    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'user_id parameter is required']);
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

        $d = $userSnap->data();
        $locId = $d['active_location_id'] ?? $d['location_id'] ?? '';
        $locationName = 'Unknown';
        $approvedSenderId = null;
        $freeUsageCount = 0;
        $freeCreditsTotal = 10;

        if (!empty($locId)) {
            // Fetch location name
            $tokSnap = $db->collection('ghl_tokens')->document($locId)->snapshot();
            if ($tokSnap->exists()) {
                $tokData = $tokSnap->data();
                $locationName = $tokData['location_name'] ?? $tokData['locationName'] ?? 'Unknown';
            }

            // Fetch integration config
            $intDocId = CreditManager::integration_doc_id_for_location((string)$locId);
            $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($intSnap->exists()) {
                $intData = $intSnap->data();
                if ($locationName === 'Unknown' || empty($locationName)) {
                    $locationName = $intData['location_name'] ?? 'Unknown';
                }
                $approvedSenderId = $intData['approved_sender_id'] ?? null;
                $freeUsageCount   = (int)($intData['free_usage_count'] ?? 0);
                $freeCreditsTotal = (int)($intData['free_credits_total'] ?? 10);
            }
        }

        // Split name if first/last names are empty but full name exists
        $firstName = $d['firstName'] ?? '';
        $lastName  = $d['lastName'] ?? '';
        $fullName  = $d['name'] ?? '';
        if (empty($firstName) && empty($lastName) && !empty($fullName)) {
            $parts = preg_split('/\s+/', trim((string)$fullName));
            $firstName = $parts[0] ?? '';
            $lastName  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        }

        $profileData = [
            'id'                 => $userId,
            'name'               => $fullName,
            'firstName'          => $firstName,
            'lastName'           => $lastName,
            'email'              => $d['email'] ?? '',
            'phone'              => $d['phone'] ?? '',
            'role'               => $d['role'] ?? 'user',
            'active'             => !array_key_exists('active', $d) || !empty($d['active']),
            'location_id'        => !empty($locId) ? $locId : null,
            'location_name'      => $locationName,
            'company_id'         => $d['company_id'] ?? null,
            'credit_balance'     => (int)($d['credit_balance'] ?? 0),
            'free_usage_count'   => $freeUsageCount,
            'free_credits_total' => $freeCreditsTotal,
            'approved_sender_id' => $approvedSenderId,
            'source'             => $d['source'] ?? 'marketplace_install',
            'created_at'         => format_ts($d['created_at'] ?? null)
        ];

        echo json_encode([
            'status' => 'success',
            'data'   => $profileData
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── POST: Update Single User Profile ─────────────────────────────────────────
if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $userId = $input['user_id'] ?? '';
    $name   = trim($input['name'] ?? '');
    $email  = trim($input['email'] ?? '');
    $phone  = trim($input['phone'] ?? '');

    if (empty($userId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'user_id is required']);
        exit;
    }

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'name is required']);
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

        $nameParts = preg_split('/\s+/', $name);
        $firstName = $nameParts[0] ?? '';
        $lastName  = count($nameParts) > 1 ? implode(' ', array_slice($nameParts, 1)) : '';

        $userRef->update([
            ['path' => 'name', 'value' => $name],
            ['path' => 'firstName', 'value' => $firstName],
            ['path' => 'lastName', 'value' => $lastName],
            ['path' => 'email', 'value' => $email],
            ['path' => 'phone', 'value' => $phone],
            ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())]
        ]);

        echo json_encode([
            'status'  => 'success',
            'message' => 'Profile updated successfully.'
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ─── Fallback ────────────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
