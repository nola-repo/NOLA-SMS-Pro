<?php
/**
 * api/agency/subaccount_profile.php
 *
 * Agency Subaccount Profile Read + Update API
 * Writes only to Firestore `users` document.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../jwt_helper.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../cache_helper.php';

// Format Firestore timestamp
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

$agencyId = validate_agency_request();
$db       = get_firestore();
$method   = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $locationId = $_GET['location_id'] ?? '';

    if (empty($locationId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'location_id parameter is required']);
        exit;
    }

    try {
        // Validate that the location belongs to this agency
        $subRef = $db->collection('agency_subaccounts')->document($locationId);
        $subSnap = $subRef->snapshot();

        if (!$subSnap->exists() || trim($subSnap->data()['agency_id'] ?? '') !== trim($agencyId)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized or location not found']);
            exit;
        }

        // Query users collection for this location
        $usersRef = $db->collection('users');
        $userSnap = null;

        // Try location_id
        $usersDocs = $usersRef->where('location_id', '=', $locationId)->limit(1)->documents();
        foreach ($usersDocs as $doc) {
            if ($doc->exists()) {
                $userSnap = $doc;
                break;
            }
        }

        // Try active_location_id if not found
        if (!$userSnap) {
            $usersDocs = $usersRef->where('active_location_id', '=', $locationId)->limit(1)->documents();
            foreach ($usersDocs as $doc) {
                if ($doc->exists()) {
                    $userSnap = $doc;
                    break;
                }
            }
        }

        if (!$userSnap) {
            // Return placeholder if user is not provisioned yet
            $subData = $subSnap->data();
            $profileData = [
                'id'                 => 'provision_pending',
                'name'               => 'Provision Pending',
                'firstName'          => 'Provision',
                'lastName'           => 'Pending',
                'email'              => '',
                'phone'              => '',
                'role'               => 'user',
                'active'             => false,
                'location_id'        => $locationId,
                'location_name'      => $subData['location_name'] ?? 'Unknown Location',
                'company_id'         => $agencyId,
                'credit_balance'     => (int)($subData['credit_balance'] ?? 0),
                'free_usage_count'   => 0,
                'free_credits_total' => 10,
                'approved_sender_id' => null,
                'source'             => 'marketplace_install',
                'created_at'         => null
            ];
        } else {
            $d = $userSnap->data();
            $locationName = $subSnap->data()['location_name'] ?? 'Unknown Location';
            $approvedSenderId = null;
            $freeUsageCount = 0;
            $freeCreditsTotal = 10;

            // Fetch integration config
            $intDocId = CreditManager::integration_doc_id_for_location((string)$locationId);
            $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
            if ($intSnap->exists()) {
                $intData = $intSnap->data();
                if (isset($intData['location_name'])) {
                    $locationName = $intData['location_name'];
                }
                $approvedSenderId = $intData['approved_sender_id'] ?? null;
                $freeUsageCount   = (int)($intData['free_usage_count'] ?? 0);
                $freeCreditsTotal = (int)($intData['free_credits_total'] ?? 10);
            }

            // Split name if needed
            $firstName = $d['firstName'] ?? '';
            $lastName  = $d['lastName'] ?? '';
            $fullName  = $d['name'] ?? '';
            if (empty($firstName) && empty($lastName) && !empty($fullName)) {
                $parts = preg_split('/\s+/', trim((string)$fullName));
                $firstName = $parts[0] ?? '';
                $lastName  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
            }

            $profileData = [
                'id'                 => $userSnap->id(),
                'name'               => $fullName,
                'firstName'          => $firstName,
                'lastName'           => $lastName,
                'email'              => $d['email'] ?? '',
                'phone'              => $d['phone'] ?? '',
                'role'               => $d['role'] ?? 'user',
                'active'             => !array_key_exists('active', $d) || !empty($d['active']),
                'location_id'        => $locationId,
                'location_name'      => $locationName,
                'company_id'         => $d['company_id'] ?? $agencyId,
                'credit_balance'     => (int)($d['credit_balance'] ?? 0),
                'free_usage_count'   => $freeUsageCount,
                'free_credits_total' => $freeCreditsTotal,
                'approved_sender_id' => $approvedSenderId,
                'source'             => $d['source'] ?? 'marketplace_install',
                'created_at'         => format_ts($d['created_at'] ?? null)
            ];
        }

        echo json_encode(['status' => 'success', 'data' => $profileData]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'POST') {
    $input  = json_decode(file_get_contents('php://input'), true) ?? [];
    $locationId = $input['location_id'] ?? '';
    $name   = trim($input['name'] ?? '');
    $email  = trim($input['email'] ?? '');
    $phone  = trim($input['phone'] ?? '');

    if (empty($locationId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'location_id is required']);
        exit;
    }

    if (empty($name)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'name is required']);
        exit;
    }

    try {
        // Validate that the location belongs to this agency
        $subRef = $db->collection('agency_subaccounts')->document($locationId);
        $subSnap = $subRef->snapshot();

        if (!$subSnap->exists() || trim($subSnap->data()['agency_id'] ?? '') !== trim($agencyId)) {
            http_response_code(403);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized or location not found']);
            exit;
        }

        // Query users collection for this location
        $usersRef = $db->collection('users');
        $userSnap = null;

        // Try location_id
        $usersDocs = $usersRef->where('location_id', '=', $locationId)->limit(1)->documents();
        foreach ($usersDocs as $doc) {
            if ($doc->exists()) {
                $userSnap = $doc;
                break;
            }
        }

        // Try active_location_id if not found
        if (!$userSnap) {
            $usersDocs = $usersRef->where('active_location_id', '=', $locationId)->limit(1)->documents();
            foreach ($usersDocs as $doc) {
                if ($doc->exists()) {
                    $userSnap = $doc;
                    break;
                }
            }
        }

        if (!$userSnap) {
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => 'User not provisioned yet. Cannot edit profile.']);
            exit;
        }

        $userId = $userSnap->id();
        $userRef = $db->collection('users')->document($userId);

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

        // Invalidate cache
        NolaCache::delete("admin_user_profile_" . $userId);
        NolaCache::delete("account_profile_" . $locationId);
        NolaCache::invalidateAdminDashboard();
        NolaCache::invalidateAgencyDashboard($agencyId);

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

http_response_code(405);
echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
