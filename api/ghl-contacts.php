<?php

/**
 * GET /api/ghl-contacts
 *
 * Retrieves GHL contacts for a given location using its stored OAuth token.
 *
 * Query Parameters:
 *   locationId  (required) — GHL location ID
 *
 * Responses:
 *   200  { contacts: [...] }
 *   400  { error: "Missing locationId" }
 *   401  { error: "OAuth token invalid or expired" }
 *   404  { error: "No OAuth token found for this location" }
 *   500  { error: "..." }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

// ── 1. Read & validate locationId ─────────────────────────────────────────────

$locationId = $_GET['locationId'] ?? $_GET['location_id'] ?? null;

error_log("[ghl-contacts] locationId received: " . ($locationId ?? 'NONE'));

if (empty($locationId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing locationId']);
    exit;
}

// ── 2. Retrieve OAuth token from Firestore ────────────────────────────────────

$db = get_firestore();

/**
 * Look up the stored OAuth token for the given locationId.
 * Canonical storage: collection=ghl_tokens, doc ID=raw locationId.
 * Falls back to a field query in case the doc was saved differently.
 */
function getTokenForLocation($db, string $locationId): ?array
{
    // Primary lookup: doc ID = raw locationId (written by ghl_oauth.php / ghl_callback.php)
    $doc = $db->collection('ghl_tokens')->document($locationId)->snapshot();

    if ($doc->exists()) {
        $data = $doc->data();
        $data['_firestore_doc_id'] = $locationId;
        return $data;
    }

    // Secondary lookup: search by location_id field (handles any legacy docs)
    $results = $db
        ->collection('ghl_tokens')
        ->where('location_id', '==', $locationId)
        ->limit(1)
        ->documents();

    foreach ($results as $snap) {
        if ($snap->exists()) {
            $data = $snap->data();
            $data['_firestore_doc_id'] = $snap->id();
            return $data;
        }
    }

    return null;
}

/**
 * Refresh GHL OAuth token and update Firestore.
 */
function refreshGHLToken($db, array $integration): string
{
    $clientId = getenv('GHL_CLIENT_ID');
    $clientSecret = getenv('GHL_CLIENT_SECRET');
    $refreshToken = $integration['refresh_token'] ?? null;
    $docId = $integration['_firestore_doc_id'] ?? $integration['location_id'] ?? null;

    if (!$clientId || !$clientSecret || !$refreshToken || !$docId) {
        throw new Exception("Missing credentials or refresh token for refresh operation.");
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
        throw new Exception("GHL token refresh failed: " . ($data['error_description'] ?? $response));
    }

    $expiresAtUnix = time() + (int)($data['expires_in'] ?? 0);
    $updateData = [
        'access_token' => $data['access_token'] ?? null,
        'refresh_token' => $data['refresh_token'] ?? null,
        'expires_at' => $expiresAtUnix,
        'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
    ];

    $db->collection('ghl_tokens')->document((string)$docId)->set($updateData, ['merge' => true]);

    return $data['access_token'];
}

/**
 * Execute a GHL request with one automatic retry on 401.
 */
function executeGHLRequest(string $url, array $headers, $db, array &$integration, string $method = 'GET', $postFields = null): array
{
    $attempt = 1;
    while ($attempt <= 2) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($postFields !== null) {
            $opts[CURLOPT_POSTFIELDS] = is_array($postFields) ? json_encode($postFields) : $postFields;
        }

        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['status' => 500, 'body' => json_encode(['error' => 'cURL error', 'details' => $error])];
        }

        if ($status === 401 && $attempt === 1) {
            try {
                error_log("[ghl-contacts] 401 detected. Attempting token refresh...");
                $newToken = refreshGHLToken($db, $integration);
                $integration['access_token'] = $newToken;

                // Update Authorization header for retry
                foreach ($headers as &$h) {
                    if (str_starts_with($h, 'Authorization: Bearer')) {
                        $h = "Authorization: Bearer {$newToken}";
                        break;
                    }
                }
                $attempt++;
                continue;
            }
            catch (Exception $e) {
                error_log("[ghl-contacts] Refresh failed: " . $e->getMessage());
                return ['status' => 401, 'body' => json_encode(['error' => 'Token refresh failed', 'details' => $e->getMessage()])];
            }
        }

        return ['status' => $status, 'body' => $body];
    }
    return ['status' => 500, 'body' => 'Unknown error in executeGHLRequest'];
}

$integration = getTokenForLocation($db, $locationId);

if ($integration === null) {
    error_log("[ghl-contacts] Token NOT found for locationId: {$locationId}");
    http_response_code(404);
    echo json_encode(['error' => 'No OAuth token found for this location']);
    exit;
}

// ── 3. Proactive Refresh (if expires in < 5 mins) ──────────────────────────────

$now = time();
$expiresAt = $integration['expires_at'] ?? 0;
// Handle both Unix int and Firestore Timestamp
$expiresSeconds = ($expiresAt instanceof \Google\Cloud\Core\Timestamp) ? $expiresAt->get()->getTimestamp() : (int)$expiresAt;

if ($expiresSeconds > 0 && ($expiresSeconds - $now < 300)) {
    try {
        error_log("[ghl-contacts] Proactive refresh for locationId: {$locationId}");
        $integration['access_token'] = refreshGHLToken($db, $integration);
    }
    catch (Exception $e) {
        error_log("[ghl-contacts] Proactive refresh failed (will try with current token): " . $e->getMessage());
    }
}

$accessToken = $integration['access_token'] ?? null;

if (empty($accessToken)) {
    http_response_code(500);
    echo json_encode(['error' => 'Stored OAuth token is empty']);
    exit;
}

// ── 4. Handle CRUD Requests ───────────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$headers = [
    "Authorization: Bearer {$accessToken}",
    'Version: 2021-07-28',
    'Accept: application/json',
    'Content-Type: application/json',
];

if ($method === 'GET') {
    $ghlUrl = 'https://services.leadconnectorhq.com/contacts/?locationId=' . urlencode($locationId) . '&limit=100';
    $resp = executeGHLRequest($ghlUrl, $headers, $db, $integration);

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    $data = json_decode($resp['body'], true);
    $contacts = $data['contacts'] ?? $data['data'] ?? (is_array($data) ? $data : []);
    error_log("[ghl-contacts] Successfully fetched " . count($contacts) . " contacts for locationId: {$locationId}");
    echo json_encode(['contacts' => $contacts]);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $parts = explode(' ', $body['name'] ?? '', 2);
    $ghlBody = [
        'locationId' => $locationId,
        'firstName' => $parts[0] ?? '',
        'lastName' => $parts[1] ?? '',
        'phone' => $body['phone'] ?? '',
    ];

    if (!empty($body['email'])) {
        $ghlBody['email'] = $body['email'];
    }

    $ghlUrl = 'https://services.leadconnectorhq.com/contacts/';
    $resp = executeGHLRequest($ghlUrl, $headers, $db, $integration, 'POST', $ghlBody);

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    $data = json_decode($resp['body'], true);
    $contact = $data['contact'] ?? $data;

    echo json_encode([
        'id' => $contact['id'] ?? null,
        'name' => ($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''),
        'phone' => $contact['phone'] ?? '',
        'email' => $contact['email'] ?? '',
    ]);
    exit;
}

if ($method === 'PUT') {
    $body = json_decode(file_get_contents('php://input'), true);
    $contactId = $body['id'] ?? $_GET['id'] ?? null;

    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing contact id']);
        exit;
    }

    $parts = explode(' ', $body['name'] ?? '', 2);
    $ghlBody = [
        'firstName' => $parts[0] ?? '',
        'lastName' => $parts[1] ?? '',
        'phone' => $body['phone'] ?? '',
    ];

    if (!empty($body['email'])) {
        $ghlBody['email'] = $body['email'];
    }

    $ghlUrl = "https://services.leadconnectorhq.com/contacts/{$contactId}";
    $resp = executeGHLRequest($ghlUrl, $headers, $db, $integration, 'PUT', $ghlBody);

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    $data = json_decode($resp['body'], true);
    $contact = $data['contact'] ?? $data;

    echo json_encode([
        'id' => $contact['id'] ?? $contactId,
        'name' => ($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''),
        'phone' => $contact['phone'] ?? '',
        'email' => $contact['email'] ?? '',
    ]);
    exit;
}

if ($method === 'DELETE') {
    $contactId = $_GET['id'] ?? null;

    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing contact id']);
        exit;
    }

    $ghlUrl = "https://services.leadconnectorhq.com/contacts/{$contactId}";
    $resp = executeGHLRequest($ghlUrl, $headers, $db, $integration, 'DELETE');

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    echo json_encode(['success' => $resp['status'] === 200 || $resp['status'] === 204]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
