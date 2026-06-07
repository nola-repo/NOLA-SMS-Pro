<?php
/**
 * POST /api/agency/ghl_autologin
 * GHL Iframe Auto-Login.
 *
 * Called by the Agency frontend when it detects a companyId in the iframe URL.
 * Looks up the agency account linked to that company_id and issues a JWT
 * WITHOUT requiring email/password — GHL context is the implicit auth.
 *
 * Payload:  { "company_id": "ABC123" }
 * Response: { token, role, company_id, user: {firstName,lastName,email} }
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/../auth/user_profile_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input     = json_decode(file_get_contents('php://input'), true);
$companyId = trim($input['company_id'] ?? '');

if (!$companyId) {
    error_log('[GHL_AUTOLOGIN] Attempted auto-login without company_id.');
    http_response_code(400);
    echo json_encode(['error' => 'company_id is required.']);
    exit;
}
error_log('[GHL_AUTOLOGIN] Attempting auto-login for company_id: ' . $companyId);

$jwtSecret = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';

function has_agency_token($db, string $companyId): bool
{
    $tokenDoc = $db->collection('ghl_tokens')->document($companyId)->snapshot();
    if ($tokenDoc->exists()) {
        $data = $tokenDoc->data();
        $isAgencyApp = ($data['appType'] ?? '') === 'agency';
        $isCompanyToken = ($data['userType'] ?? 'Company') === 'Company';
        if ($isAgencyApp && $isCompanyToken) {
            return true;
        }
    }

    $tokenDocs = $db->collection('ghl_tokens')
        ->where('companyId', '=', $companyId)
        ->documents();

    foreach ($tokenDocs as $doc) {
        if (!$doc->exists()) {
            continue;
        }

        $data = $doc->data();
        $isAgencyApp = ($data['appType'] ?? '') === 'agency';
        $isCompanyToken = ($data['userType'] ?? '') === 'Company' || $doc->id() === $companyId;

        if ($isAgencyApp && $isCompanyToken) {
            return true;
        }
    }

    return false;
}

try {
    $db = get_firestore();

    // ── Find the agency account linked to this company_id ────────────────────
    $authCollection = 'agency_users';
    $results = $db->collection('agency_users')
        ->where('company_id', '=', $companyId)
        ->limit(1)
        ->documents();

    $userId   = null;
    $userData = null;
    foreach ($results as $doc) {
        if ($doc->exists()) {
            $userId   = $doc->id();
            $userData = $doc->data();
            break;
        }
    }

    if (!$userData) {
        $authCollection = 'users';
        $results = $db->collection('users')
            ->where('role',       '=', 'agency')
            ->where('company_id', '=', $companyId)
            ->limit(1)
            ->documents();

        foreach ($results as $doc) {
            if ($doc->exists()) {
                $userId   = $doc->id();
                $userData = $doc->data();
                break;
            }
        }
    }

    if (!$userData) {
        error_log('[GHL_AUTOLOGIN] No existing agency user found for company_id: ' . $companyId . '. Verifying ghl_tokens...');

        if (!has_agency_token($db, $companyId)) {
            error_log('[GHL_AUTOLOGIN] Company is not a registered agency-level install in ghl_tokens.');
            http_response_code(404);
            echo json_encode(['error' => 'No agency account is linked to this GHL company. Please install the Agency App first.']);
            exit;
        }

        error_log('[GHL_AUTOLOGIN] Validation passed. Creating new user doc on the fly.');
        $userData = [
            'role'       => 'agency',
            'company_id' => $companyId,
            'createdAt'  => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'active'     => true,
            'email'      => 'agency_' . $companyId . '@ghl.nolasmspro.com'
        ];
        $newUserRef = $db->collection('agency_users')->add($userData);
        $userId = $newUserRef->id();
        $authCollection = 'agency_users';
    } else {
        error_log('[GHL_AUTOLOGIN] Found existing agency user for company_id: ' . $companyId . ' (User ID: ' . $userId . ')');
    }

    if (empty($userData['active'])) {
        http_response_code(403);
        echo json_encode(['error' => 'This agency account has been deactivated.']);
        exit;
    }

    if (empty($userData['company_name'])) {
        foreach (['ghl_agency_tokens', 'ghl_tokens'] as $collection) {
            try {
                $snap = $db->collection($collection)->document($companyId)->snapshot();
                if (!$snap->exists()) {
                    continue;
                }
                $tokenData = $snap->data();
                $companyName = $tokenData['company_name']
                    ?? $tokenData['agency_name']
                    ?? $tokenData['location_name']
                    ?? null;
                if ($companyName !== null && trim((string)$companyName) !== '') {
                    $userData['company_name'] = trim((string)$companyName);
                    break;
                }
            } catch (Exception $ignored) {
            }
        }
    }

    $profile = auth_user_payload_for_api($userData, (string)($userData['email'] ?? ''));

    // ── Sign JWT (8 h) ────────────────────────────────────────────────────────
    $token = jwt_sign([
        'sub'             => $userId,
        'email'           => $userData['email'] ?? '',
        'role'            => 'agency',
        'company_id'      => $companyId,
        'auth_collection' => $authCollection,
    ], $jwtSecret, 28800);

    echo json_encode([
        'token'      => $token,
        'role'       => 'agency',
        'company_id' => $companyId,
        'user'       => $profile,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Auto-login failed: ' . $e->getMessage()]);
}
