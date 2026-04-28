<?php
/**
 * diagnose_token.php — GHL Token Diagnostic Tool
 *
 * Reads the token for a given locationId from Firestore and tests
 * whether the refresh_token is still valid against both GHL app credentials.
 *
 * Usage: php diagnose_token.php [locationId]
 *   OR visit: https://your-domain.com/tmp/diagnose_token.php?locationId=ugBqfQsPtGijLjrmLdmA&secret=f7RkQ2pL9zV3tX8cB1nS4yW6
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../api/webhook/firestore_client.php';
require __DIR__ . '/../api/auth_helpers.php';

// ── Auth (web mode only) ─────────────────────────────────────────────────────
if (PHP_SAPI !== 'cli') {
    validate_api_request();
    header('Content-Type: text/plain; charset=utf-8');
}

// ── Resolve locationId ────────────────────────────────────────────────────────
$locationId = $_GET['locationId'] ?? $_GET['location_id'] ?? ($argv[1] ?? null);
if (!$locationId) {
    $locationId = 'ugBqfQsPtGijLjrmLdmA'; // Default to the known-broken location
}

$db = get_firestore();

echo "=============================================================\n";
echo " NOLA SMS — GHL Token Diagnostic\n";
echo " Location ID : $locationId\n";
echo " Timestamp   : " . date('Y-m-d H:i:s T') . "\n";
echo "=============================================================\n\n";

// ── Step 1: Read from Firestore ───────────────────────────────────────────────
echo "[ STEP 1 ] Reading ghl_tokens/{$locationId} from Firestore...\n";
$doc = $db->collection('ghl_tokens')->document($locationId)->snapshot();

if (!$doc->exists()) {
    echo "  ✗ ERROR: Document does not exist in Firestore.\n";
    echo "  → This location has never been authenticated or was deleted.\n";
    echo "  → ACTION: Re-install the NOLA SMS Pro app from the GHL Marketplace.\n";
    exit(1);
}

$data = $doc->data();

// ── Display token metadata ────────────────────────────────────────────────────
$accessToken   = $data['access_token']  ?? null;
$refreshToken  = $data['refresh_token'] ?? null;
$storedClientId = $data['client_id']   ?? $data['appId'] ?? '(not set)';
$expiresAt     = $data['expires_at']   ?? null;
$updatedAt     = $data['updated_at']   ?? null;
$companyId     = $data['companyId']    ?? '(not set)';
$userType      = $data['userType']     ?? '(not set)';
$toggleEnabled = $data['toggle_enabled'] ?? true;
$isLive        = $data['is_live'] ?? '(not set)';

// Handle Firestore Timestamp objects
$expiresAtStr = 'unknown';
if ($expiresAt instanceof \Google\Cloud\Core\Timestamp) {
    $ts = $expiresAt->get()->getTimestamp();
    $expiresAtStr = date('Y-m-d H:i:s T', $ts) . ' (' . ($ts - time() > 0 ? '+' . ($ts - time()) . 's from now' : (time() - $ts) . 's AGO') . ')';
} elseif (is_int($expiresAt)) {
    $expiresAtStr = date('Y-m-d H:i:s T', $expiresAt) . ' (' . ($expiresAt - time() > 0 ? '+' . ($expiresAt - time()) . 's from now' : (time() - $expiresAt) . 's AGO') . ')';
}

$updatedAtStr = 'unknown';
if ($updatedAt instanceof \Google\Cloud\Core\Timestamp) {
    $updatedAtStr = date('Y-m-d H:i:s T', $updatedAt->get()->getTimestamp());
} elseif (is_int($updatedAt)) {
    $updatedAtStr = date('Y-m-d H:i:s T', $updatedAt);
}

echo "  ✓ Document found.\n\n";
echo "  Stored client_id  : $storedClientId\n";
echo "  companyId         : $companyId\n";
echo "  userType          : $userType\n";
echo "  toggle_enabled    : " . ($toggleEnabled ? 'true' : 'false') . "\n";
echo "  is_live           : " . ($isLive === true ? 'true' : ($isLive === false ? 'false' : $isLive)) . "\n";
echo "  expires_at        : $expiresAtStr\n";
echo "  updated_at        : $updatedAtStr\n";
echo "  access_token      : " . ($accessToken ? substr($accessToken, 0, 20) . '...' : '(MISSING)') . "\n";
echo "  refresh_token     : " . ($refreshToken ? substr($refreshToken, 0, 20) . '...' : '(MISSING)') . "\n\n";

// ── Step 2: Credential mismatch check ────────────────────────────────────────
echo "[ STEP 2 ] Checking for phantom/mismatched client_id...\n";

$knownUserAppId   = getenv('GHL_CLIENT_ID')        ?: '6999da2b8f278296d95f7274-mm9wv85e';
$knownAgencyAppId = getenv('GHL_AGENCY_CLIENT_ID') ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
$phantomIds       = ['69aa6cc3']; // Known bad phantom IDs from previous installs

$isPhantom = false;
foreach ($phantomIds as $phantom) {
    if (str_contains((string)$storedClientId, $phantom)) {
        $isPhantom = true;
        echo "  ✗ PHANTOM CLIENT ID DETECTED: '$storedClientId'\n";
        echo "  → This token was generated with a stale/retired app credential.\n";
        echo "  → GHL will permanently reject this refresh_token.\n";
        echo "  → ACTION: Re-install the app for this location.\n\n";
        break;
    }
}

if (!$isPhantom) {
    if ($storedClientId === $knownUserAppId) {
        echo "  ✓ client_id matches the User App (correct).\n\n";
    } elseif ($storedClientId === $knownAgencyAppId) {
        echo "  ✓ client_id matches the Agency App (correct).\n\n";
    } else {
        echo "  ⚠ client_id '$storedClientId' does not match either known app ID.\n";
        echo "    Known User App   : $knownUserAppId\n";
        echo "    Known Agency App : $knownAgencyAppId\n";
        echo "  → This may still work if the app was recently updated. Check the test below.\n\n";
    }
}

// ── Step 3: Live refresh_token test ──────────────────────────────────────────
echo "[ STEP 3 ] Testing refresh_token against GHL API...\n";

if (!$refreshToken) {
    echo "  ✗ No refresh_token in Firestore. Cannot test.\n";
    echo "  → ACTION: Re-install the NOLA SMS Pro app for this location.\n";
    exit(1);
}

$credentialSets = [
    'User App'   => [
        'client_id'     => getenv('GHL_CLIENT_ID')        ?: '6999da2b8f278296d95f7274-mm9wv85e',
        'client_secret' => getenv('GHL_CLIENT_SECRET')    ?: 'dfc4380f-6132-49b3-8246-92e14f55ee78',
        'user_type'     => 'Location',
    ],
    'Agency App' => [
        'client_id'     => getenv('GHL_AGENCY_CLIENT_ID')    ?: '69d31f33b3071b25dbcc5656-mnqxvtt3',
        'client_secret' => getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322',
        'user_type'     => 'Company',
    ],
];

$anySuccess = false;
foreach ($credentialSets as $label => $creds) {
    echo "  Testing with $label ({$creds['client_id']})...\n";

    $ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'client_id'     => $creds['client_id'],
            'client_secret' => $creds['client_secret'],
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'user_type'     => $creds['user_type'],
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'Version: 2021-07-28',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);

    if ($httpCode === 200 && isset($result['access_token'])) {
        $anySuccess = true;
        echo "  ✓ SUCCESS (HTTP $httpCode) — Token is valid and refreshable!\n";
        echo "    New access_token : " . substr($result['access_token'], 0, 20) . "...\n";
        echo "    Expires in       : " . ($result['expires_in'] ?? '?') . "s\n\n";

        // Optionally auto-write the new token back to Firestore
        $now = new DateTimeImmutable();
        $expiresIn = (int)($result['expires_in'] ?? 86399);
        $db->collection('ghl_tokens')->document($locationId)->set([
            'access_token'  => $result['access_token'],
            'refresh_token' => $result['refresh_token'] ?? $refreshToken,
            'expires_at'    => time() + $expiresIn,
            'client_id'     => $creds['client_id'],
            'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
            'raw_refresh'   => $result,
        ], ['merge' => true]);

        // Also invalidate the file cache so GhlClient picks up the new token immediately
        $cacheDir = sys_get_temp_dir() . '/nola_cache/tokens';
        $cacheFile = $cacheDir . '/' . md5('token_' . $locationId) . '.json';
        if (file_exists($cacheFile)) {
            unlink($cacheFile);
            echo "    ✓ Cleared local GhlClient token cache.\n";
        }

        echo "    ✓ New token written back to Firestore automatically.\n";
    } else {
        $errorMsg = $result['error_description'] ?? $result['message'] ?? $result['error'] ?? $response;
        echo "  ✗ FAILED (HTTP $httpCode): $errorMsg\n\n";
    }
}

// ── Final verdict ─────────────────────────────────────────────────────────────
echo "=============================================================\n";
if ($anySuccess) {
    echo " ✅ RESULT: Token is healthy. Firestore has been auto-updated.\n";
    echo "    GHL sync should now work for location $locationId.\n";
} else {
    echo " ❌ RESULT: refresh_token is PERMANENTLY DEAD.\n";
    echo "\n REQUIRED ACTION (no code can fix this):\n";
    echo "  1. In GHL → go to Settings → Integrations → NOLA SMS Pro\n";
    echo "  2. Click 'Disconnect' or 'Uninstall'\n";
    echo "  3. Reinstall the app from the GHL Marketplace\n";
    echo "  4. Complete the registration/OAuth flow\n";
    echo "  5. Re-run this script to confirm the new token is valid\n";
}
echo "=============================================================\n";
