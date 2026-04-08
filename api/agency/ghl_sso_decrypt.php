<?php

/**
 * GHL SSO Decrypt Endpoint
 *
 * Decrypts the encrypted payload received from GoHighLevel's
 * REQUEST_USER_DATA postMessage handshake. Returns the decrypted
 * user context (companyId, userId, activeLocation).
 *
 * The encrypted payload uses CryptoJS AES-256-CBC with OpenSSL's
 * legacy EVP_BytesToKey (MD5) key derivation.
 *
 * Usage: POST /api/agency/ghl_sso_decrypt
 * Body:  { "encryptedPayload": "U2FsdGVkX1+..." }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$encryptedPayload = trim($input['encryptedPayload'] ?? '');

if (empty($encryptedPayload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'encryptedPayload is required']);
    exit;
}

// ── Shared Secret from GHL Developer Portal ─────────────────────────────────
$sharedSecret = getenv('GHL_SSO_SECRET') ?: '4d205fb2-9c8d-4575-a43d-f2f68280decd';

/**
 * Replicates OpenSSL's legacy EVP_BytesToKey using MD5.
 * CryptoJS uses this when encrypting with a passphrase string.
 *
 * @param string $salt      8-byte salt extracted from the ciphertext
 * @param string $password  The shared secret / passphrase
 * @return string           48 bytes: 32 for key + 16 for IV
 */
function evp_bytes_to_key(string $salt, string $password): string
{
    $derived = '';
    $block = '';
    while (strlen($derived) < 48) { // 32 (key) + 16 (IV) = 48
        $block = md5($block . $password . $salt, true);
        $derived .= $block;
    }
    return $derived;
}

/**
 * Decrypts a CryptoJS AES-256-CBC encrypted payload.
 *
 * @param string $encryptedBase64  Base64-encoded ciphertext (with Salted__ prefix)
 * @param string $passphrase       The shared secret
 * @return string|false            Decrypted plaintext or false on failure
 */
function decrypt_cryptojs(string $encryptedBase64, string $passphrase)
{
    $data = base64_decode($encryptedBase64, true);
    if ($data === false || strlen($data) < 17) {
        return false;
    }

    // 1. Validate "Salted__" header
    if (substr($data, 0, 8) !== 'Salted__') {
        return false;
    }

    // 2. Extract salt (8 bytes) and ciphertext
    $salt = substr($data, 8, 8);
    $ciphertext = substr($data, 16);

    // 3. Derive key (32 bytes) and IV (16 bytes) via EVP_BytesToKey
    $keyIv = evp_bytes_to_key($salt, $passphrase);
    $key = substr($keyIv, 0, 32);
    $iv = substr($keyIv, 32, 16);

    // 4. Decrypt using AES-256-CBC
    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-cbc',
        $key,
        OPENSSL_RAW_DATA,
        $iv
    );

    return $decrypted;
}

// ── Decrypt the GHL SSO Payload ─────────────────────────────────────────────
try {
    $decrypted = decrypt_cryptojs($encryptedPayload, $sharedSecret);

    if ($decrypted === false) {
        error_log('[GHL_SSO] Decryption failed for payload: ' . substr($encryptedPayload, 0, 50) . '...');
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Failed to decrypt SSO payload. Verify your Shared Secret.']);
        exit;
    }

    $userData = json_decode($decrypted, true);

    if (!is_array($userData)) {
        error_log('[GHL_SSO] Decrypted data is not valid JSON: ' . $decrypted);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Decrypted payload is not valid JSON.']);
        exit;
    }

    $companyId = $userData['companyId'] ?? $userData['company_id'] ?? null;
    $userId = $userData['userId'] ?? $userData['user_id'] ?? null;
    $activeLocation = $userData['activeLocation'] ?? $userData['location_id'] ?? null;

    error_log(sprintf(
        '[GHL_SSO] Decrypted successfully — companyId=%s userId=%s activeLocation=%s',
        $companyId ?? '(null)',
        $userId ?? '(null)',
        $activeLocation ?? '(null)'
    ));

    echo json_encode([
        'success'        => true,
        'companyId'      => $companyId,
        'userId'         => $userId,
        'activeLocation' => $activeLocation,
        'raw'            => $userData, // Full decrypted context for debugging
    ]);

} catch (Exception $e) {
    error_log('[GHL_SSO] Exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error during SSO decrypt.']);
}
