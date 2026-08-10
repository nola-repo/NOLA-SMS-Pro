<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();

if (!$locId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$docId = (string) $locId;

function nola_mask_secret(?string $secret): ?string
{
    $secret = trim((string)($secret ?? ''));
    if ($secret === '') {
        return null;
    }
    $last = substr($secret, -4);
    $prefix = substr($secret, 0, 3);
    return $prefix . '...' . $last;
}

try {
    if ($method === 'GET') {
        // 3. Database Query — fetch integration doc + admin_settings + ghl_token in parallel
        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);

        // Read integration doc
        $intSnap = $db->collection('integrations')->document($intDocId)->snapshot();
        $data = $intSnap->exists() ? $intSnap->data() : [];

        // Read admin settings doc to get the real system-level sender name (e.g. "NOLA")
        $adminSettingsSnap = $db->collection('admin_config')->document('admin_settings')->snapshot();
        $adminSettings = $adminSettingsSnap->exists() ? $adminSettingsSnap->data() : [];
        // sms_provider.unisms_sender_id is where the admin configures the master sender name
        $systemDefaultSender = trim((string)(
            $adminSettings['sms_provider']['unisms_sender_id']
            ?? $adminSettings['sender_default']
            ?? 'NOLASMSPro'
        ));
        if ($systemDefaultSender === '') $systemDefaultSender = 'NOLASMSPro';

        // Read toggle status from ghl_tokens
        $tokenSnap = $db->collection('ghl_tokens')->document($locId)->snapshot();
        $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
        $toggleEnabled = isset($tokenData['toggle_enabled']) ? (bool)$tokenData['toggle_enabled'] : true;

        $approvedSenderId = $data['approved_sender_id'] ?? null;

        if ($approvedSenderId) {
            $approvedQuery = $db->collection('sender_id_requests')
                ->where('location_id', '==', $locId)
                ->where('status', '==', 'approved')
                ->documents();

            $stillApproved = false;
            foreach ($approvedQuery as $requestDoc) {
                if (!$requestDoc->exists()) continue;
                $requestData = $requestDoc->data();
                if (strtolower(trim((string)($requestData['requested_id'] ?? ''))) === strtolower(trim((string)$approvedSenderId))) {
                    $stillApproved = true;
                    break;
                }
            }

            if (!$stillApproved) {
                $approvedSenderId = null;
            }
        }

        echo json_encode([
            'status' => 'success',
            'data' => [
                'sender_id'                   => $approvedSenderId,
                'verified'                    => !empty($approvedSenderId),
                'approved_sender_id'          => $approvedSenderId,
                'nola_pro_api_key'            => null,
                'nola_pro_api_key_masked'     => nola_mask_secret($data['nola_pro_api_key'] ?? ($data['semaphore_api_key'] ?? null)),
                'nola_pro_api_key_configured' => !empty($data['nola_pro_api_key'] ?? ($data['semaphore_api_key'] ?? null)),
                'unisms_api_key'              => null,
                'unisms_api_key_masked'       => nola_mask_secret($data['unisms_api_key'] ?? null),
                'unisms_api_key_configured'   => !empty($data['unisms_api_key'] ?? null),
                'unisms_sender_id'            => $data['unisms_sender_id'] ?? null,
                'provider_preference'         => $data['provider_preference'] ?? 'system',
                'free_usage_count'            => $data['free_usage_count'] ?? 0,
                'free_credits_total'          => $data['free_credits_total'] ?? 10,
                'system_default_sender'       => $systemDefaultSender,
                'toggle_enabled'              => $toggleEnabled,
            ]
        ]);
        exit;
    }

    if ($method === 'POST') {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) $payload = $_POST;

        $apiKey = $payload['api_key'] ?? null;
        $unismsApiKey = $payload['unisms_api_key'] ?? null;
        $providerPreference = $payload['provider_preference'] ?? null;
        $unismsSenderId = $payload['unisms_sender_id'] ?? null;

        if ($providerPreference !== null && !in_array($providerPreference, ['system', 'semaphore', 'semaphore_custom', 'unisms', 'unisms_custom'], true)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid provider preference.']);
            exit;
        }

        if (!empty($apiKey)) {
            $ch = curl_init('https://api.semaphore.co/api/v4/account?apikey=' . urlencode($apiKey));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200) {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid Semaphore API Key. Verification failed.']);
                exit;
            }
        }

        if (!empty($unismsApiKey)) {
            require_once __DIR__ . '/services/providers/UniSmsProvider.php';
            $uniSms = new UniSmsProvider([
                'UNISMS_API_KEY' => $unismsApiKey
            ]);
            $accCheck = $uniSms->checkAccount();
            if ($accCheck['status'] !== 'active') {
                http_response_code(400);
                echo json_encode(['status' => 'error', 'message' => 'Invalid UniSMS API Key. Verification failed.']);
                exit;
            }
        }

        $updateData = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ];
        if ($apiKey !== null) {
            $updateData['nola_pro_api_key'] = $apiKey;
            $updateData['nola_pro_api_key_last4'] = $apiKey === '' ? null : substr((string)$apiKey, -4);
        }
        if ($unismsApiKey !== null) {
            $updateData['unisms_api_key'] = $unismsApiKey;
            $updateData['unisms_api_key_last4'] = $unismsApiKey === '' ? null : substr((string)$unismsApiKey, -4);
        }
        if ($providerPreference !== null) {
            $updateData['provider_preference'] = $providerPreference;
        }
        if ($unismsSenderId !== null) {
            $updateData['unisms_sender_id'] = $unismsSenderId;
        }

        $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
        $db->collection('integrations')->document($intDocId)->set($updateData, ['merge' => true]);

        require_once __DIR__ . '/cache_helper.php';
        NolaCache::delete('admin_users_list');

        echo json_encode(['status' => 'success', 'message' => 'Account sender configuration updated']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error', 'details' => $e->getMessage()]);
}
