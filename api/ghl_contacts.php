<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

$db = get_firestore();

$locationHeader = get_ghl_location_id();

/**
 * Get the latest GHL credentials from Firestore.
 */
function getGHLIntegration($db, $locationId = null)
{
    if ($locationId) {
        // Primary: doc ID = raw locationId (canonical format written by ghl_oauth.php / ghl_callback.php)
        $doc = $db->collection('ghl_tokens')->document((string)$locationId)->snapshot();
        if ($doc->exists()) {
            $data = $doc->data();
            $data['firestore_doc_id'] = (string)$locationId;
            return $data;
        }

        // Fallback: search by location_id field (handles any legacy docs in ghl_tokens)
        $query = $db->collection('ghl_tokens')->where('location_id', '==', $locationId)->limit(1)->documents();
        foreach ($query as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                $data['firestore_doc_id'] = $doc->id();
                return $data;
            }
        }

        // If locationId was requested but not found, return null to avoid using a different location's token
        return null;
    }

    // No locationId provided: return first available token (single-tenant fallback only)
    $integrations = $db->collection('ghl_tokens')->limit(1)->documents();
    foreach ($integrations as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();
            $data['firestore_doc_id'] = $doc->id();
            return $data;
        }
    }
    return null;
}

/**
 * Refresh GHL OAuth token and update Firestore.
 */
function refreshGHLToken($db, &$integration)
{
    $clientId = getenv('GHL_CLIENT_ID');
    $clientSecret = getenv('GHL_CLIENT_SECRET');
    $refreshToken = $integration['refresh_token'] ?? null;
    $docId = $integration['firestore_doc_id'] ?? 'ghl';

    if (!$clientId || !$clientSecret || !$refreshToken) {
        $missing = [];
        if (!$clientId) $missing[] = 'GHL_CLIENT_ID';
        if (!$clientSecret) $missing[] = 'GHL_CLIENT_SECRET';
        if (!$refreshToken) $missing[] = 'refresh_token (in Firestore)';
        
        throw new Exception("GHL Refresh Error: Missing " . implode(', ', $missing) . ". Ensure environment variables are set in Cloud Run.");
    }

    $tokenUrl = 'https://services.leadconnectorhq.com/oauth/token';
    $postData = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'user_type' => 'Location',
    ];

    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($postData),
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
            'Version: 2021-07-28',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode !== 200 || !is_array($data)) {
        throw new Exception("GHL token refresh failed with code $httpCode: " . ($data['error_description'] ?? $response));
    }

    $now = new DateTimeImmutable();
    $expires = (int)($data['expires_in'] ?? 0);
    $expiresAtUnix = time() + $expires; // Unix int — consistent with ghl_oauth.php

    $updateData = [
        'access_token'  => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'expires_at'    => $expiresAtUnix,
        'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
        'raw_refresh'   => $data,
    ];

    // Write back to ghl_tokens using the raw locationId as the doc ID
    $db->collection('ghl_tokens')->document($docId)->set($updateData, ['merge' => true]);

    // Update the local integration array so the caller has the new token immediately
    $integration['access_token'] = $data['access_token'] ?? null;
    $integration['refresh_token'] = $data['refresh_token'] ?? null;
    $integration['expires_at'] = $expiresAtUnix;

    return $data['access_token'];
}

$integration = getGHLIntegration($db, $locationHeader);
if (!$integration) {
    http_response_code(404);
    echo json_encode([
        'error' => 'GHL integration not found.',
        'requested_location' => $locationHeader ?: 'default',
        'suggestion' => 'Ensure the location ID is correctly passed in the X-GHL-Location-ID header and configured in Firestore.'
    ]);
    exit;
}

$GHL_API_URL = 'https://services.leadconnectorhq.com';
$GHL_LOCATION_ID = $locationHeader ?? $integration['location_id'] ?? null;
$GHL_API_TOKEN = $integration['access_token'] ?? null;

// PROACTIVE REFRESH: If token expires within 5 minutes, refresh it now
$isExpired = false;
if (isset($integration['expires_at'])) {
    $expiresAt = $integration['expires_at']; 
    $now = time();
    // Google Cloud Timestamp to Unix seconds if needed, or check format
    $expiresSeconds = $expiresAt instanceof \Google\Cloud\Core\Timestamp ? $expiresAt->get()->getTimestamp() : (int)$expiresAt;
    if ($expiresSeconds - $now < 300) { $isExpired = true; }
}

if ($isExpired) {
    try {
        $GHL_API_TOKEN = refreshGHLToken($db, $integration);
        header('X-GHL-Token-Refreshed: proactive');
    } catch (Exception $e) {
        // Log refresh failure but try with old token anyway (may still work if clock skew)
        error_log("Proactive refresh failed: " . $e->getMessage());
    }
}

if (!$GHL_LOCATION_ID || !$GHL_API_TOKEN) {
    http_response_code(500);
    echo json_encode([
        'error' => 'GHL configuration incomplete.',
        'details' => 'location_id or access_token missing in Firestore document: ' . ($integration['firestore_doc_id'] ?? 'unknown')
    ]);
    exit;
}

/**
 * Execute a cURL request with automatic retry on 401.
 */
function executeGHLRequest($url, $method, $headers, $payload = null, $db, &$integration)
{
    $attempt = 1;
    while ($attempt <= 2) {
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $payload;
        } elseif ($method === 'PUT') {
            $options[CURLOPT_CUSTOMREQUEST] = 'PUT';
            $options[CURLOPT_POSTFIELDS] = $payload;
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $options);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status === 401 && $attempt === 1) {
            try {
                $newToken = refreshGHLToken($db, $integration);
                // Update local token for retry
                $integration['access_token'] = $newToken;
                // Update header for retry
                foreach ($headers as &$h) {
                    if (str_starts_with($h, 'Authorization: Bearer')) {
                        $h = "Authorization: Bearer {$newToken}";
                        break;
                    }
                }
                $attempt++;
                continue;
            } catch (Exception $e) {
                return ['status' => 401, 'body' => json_encode(['error' => 'Token refresh failed', 'details' => $e->getMessage()])];
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Transform a GHL contact to our simplified format.
 */
function transformGHLContact(array $contact): array
{
    $name = $contact['name']
        ?? trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''))
        ?: 'Unknown';

    return [
        'id' => $contact['id'] ?? null,
        'name' => $name,
        'phone' => $contact['phone'] ?? $contact['mobileNumber'] ?? '',
        'email' => $contact['email'] ?? '',
    ];
}

try {
    // ── GET — fetch contacts ────────────────────────────────────────────
    if ($method === 'GET') {
        $url = "{$GHL_API_URL}/contacts?locationId={$GHL_LOCATION_ID}&limit=100";
        $headers = [
            "Authorization: Bearer {$integration['access_token']}",
            'Content-Type: application/json',
            'Version: 2021-07-28',
        ];

        $getPayload = null;
        $resp = executeGHLRequest($url, 'GET', $headers, $getPayload, $db, $integration);
        $status = $resp['status'];
        $body = $resp['body'];

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to fetch contacts from GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contacts = $data['contacts'] ?? $data['data'] ?? (is_array($data) ? $data : []);

        $transformed = array_values(array_filter(
            array_map('transformGHLContact', $contacts),
            fn($c) => !empty($c['phone'])
        ));

        echo json_encode($transformed, JSON_PRETTY_PRINT);
        exit;
    }

    // ── POST — create a contact ─────────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');

        if (!$name || !$phone) {
            http_response_code(400);
            echo json_encode(['error' => 'Name and phone are required']);
            exit;
        }

        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        // Convert to E.164 (+63…)
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '63' . substr($digits, 1);
        }
        if (!str_starts_with($digits, '63')) {
            $digits = '63' . $digits;
        }
        $phoneE164 = '+' . $digits;

        $payload = json_encode(array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phoneE164,
            'email' => $email ?: null,
            'locationId' => $GHL_LOCATION_ID,
        ]));

        $url = "{$GHL_API_URL}/contacts?locationId={$GHL_LOCATION_ID}";
        $headers = [
            "Authorization: Bearer {$integration['access_token']}",
            'Content-Type: application/json',
            'Version: 2021-07-28',
        ];

        $resp = executeGHLRequest($url, 'POST', $headers, $payload, $db, $integration);
        $status = $resp['status'];
        $body = $resp['body'];

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to create contact in GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contact = $data['contact'] ?? $data;
        echo json_encode(transformGHLContact($contact), JSON_PRETTY_PRINT);
        exit;
    }

    // ── PUT — update a contact ──────────────────────────────────────────
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = $input['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }

        $nameParts = explode(' ', trim($input['name'] ?? ''), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $phoneE164 = '';
        if (!empty($input['phone'])) {
            $digits = preg_replace('/\D/', '', $input['phone']);
            if (str_starts_with($digits, '0')) {
                $digits = '63' . substr($digits, 1);
            }
            if (!str_starts_with($digits, '63')) {
                $digits = '63' . $digits;
            }
            $phoneE164 = '+' . $digits;
        }

        $payload = json_encode(array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phoneE164 ?: null,
            'email' => $input['email'] ?? null,
        ]));

        $url = "{$GHL_API_URL}/contacts/{$id}?locationId={$GHL_LOCATION_ID}";
        $headers = [
            "Authorization: Bearer {$integration['access_token']}",
            'Content-Type: application/json',
            'Version: 2021-07-28',
        ];

        $resp = executeGHLRequest($url, 'PUT', $headers, $payload, $db, $integration);
        $status = $resp['status'];
        $body = $resp['body'];

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to update contact in GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contact = $data['contact'] ?? $data;
        echo json_encode(transformGHLContact($contact), JSON_PRETTY_PRINT);
        exit;
    }

    // ── DELETE — delete a contact ───────────────────────────────────────
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }

        $url = "{$GHL_API_URL}/contacts/{$id}?locationId={$GHL_LOCATION_ID}";
        $headers = [
            "Authorization: Bearer {$integration['access_token']}",
            'Content-Type: application/json',
            'Version: 2021-07-28',
        ];

        $delPayload = null;
        $resp = executeGHLRequest($url, 'DELETE', $headers, $delPayload, $db, $integration);
        $status = $resp['status'];
        $body = $resp['body'];

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to delete contact from GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $id], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to process GHL contact request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
