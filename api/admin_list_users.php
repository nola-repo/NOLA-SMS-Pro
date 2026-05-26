<?php
/**
 * api/admin_list_users.php
 *
 * Admin List Users API
 * Returns all documents from the `users` Firestore collection, enriched with integration data.
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

try {
    // High-performance optimization: pre-fetch integrations and ghl_tokens to avoid O(N) queries
    $integrationsSnap = $db->collection('integrations')->documents();
    $integrationMap = [];
    foreach ($integrationsSnap as $doc) {
        if ($doc->exists()) {
            $integrationMap[$doc->id()] = $doc->data();
        }
    }

    $ghlTokensSnap = $db->collection('ghl_tokens')->documents();
    $ghlTokenMap = [];
    foreach ($ghlTokensSnap as $doc) {
        if ($doc->exists()) {
            $ghlTokenMap[$doc->id()] = $doc->data();
        }
    }

    // Fetch all users
    $usersSnap = $db->collection('users')->documents();
    $usersList = [];

    foreach ($usersSnap as $doc) {
        if (!$doc->exists()) continue;
        $d = $doc->data();

        $locId = $d['active_location_id'] ?? $d['location_id'] ?? '';
        $locationName = 'Unknown';
        $approvedSenderId = null;
        $freeUsageCount = 0;
        $freeCreditsTotal = 10;

        if (!empty($locId)) {
            // Check ghl_tokens first
            if (isset($ghlTokenMap[$locId])) {
                $locationName = $ghlTokenMap[$locId]['location_name'] ?? $ghlTokenMap[$locId]['locationName'] ?? 'Unknown';
            }

            // Check integrations
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
            $intData = $integrationMap[$intDocId] ?? $integrationMap[$locId] ?? null;
            if ($intData) {
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

        $usersList[] = [
            'id'                 => $doc->id(),
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
    }

    echo json_encode([
        'status' => 'success',
        'data'   => $usersList,
        'total'  => count($usersList)
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
