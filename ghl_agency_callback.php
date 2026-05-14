<?php
require __DIR__ . '/api/webhook/firestore_client.php';
require_once __DIR__ . '/api/jwt_helper.php';

function agency_render_error(string $msg, array $details = []): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $safe = htmlspecialchars($msg);
    $detHtml = '';
    if ($details) {
        $detHtml = '<pre style="font-size:11px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;max-height:200px;overflow:auto;text-align:left;">'
            . htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT)) . '</pre>';
    }
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Agency Install Failed</title>
<style>body{font-family:sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f9fafb;margin:0}.card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;max-width:560px;width:100%;box-shadow:0 6px 24px rgba(0,0,0,.05)}h1{font-size:22px;color:#dc2626;margin:0 0 8px}p{margin:0 0 10px}</style>
</head><body><div class="card"><h1>Installation Failed</h1><p>{$safe}</p>{$detHtml}</div></body></html>
HTML;
    exit;
}

function agency_env_required(string $name): string
{
    $v = getenv($name);
    if ($v === false || trim((string)$v) === '') {
        agency_render_error("Missing required environment variable: {$name}");
    }
    return trim((string)$v);
}

function agency_redact_tokens(array $data): array
{
    $copy = $data;
    foreach (['access_token', 'refresh_token', 'id_token', 'client_secret'] as $k) {
        if (isset($copy[$k])) {
            $copy[$k] = '[REDACTED]';
        }
    }
    return $copy;
}

function agency_curl_json(string $url, string $token): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Version: 2021-07-28',
        ],
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode((string)$resp, true) ?? []];
}

function agency_trigger_provision_async(string $baseUrl, string $sessionId, string $secret): void
{
    $url = rtrim($baseUrl, '/') . '/api/agency/install/provision?session_id=' . urlencode($sessionId);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT_MS => 2000,
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'X-Webhook-Secret: ' . $secret],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

$agencyClientId = agency_env_required('GHL_AGENCY_CLIENT_ID');
$agencyClientSecret = agency_env_required('GHL_AGENCY_CLIENT_SECRET');
$jwtSecret = agency_env_required('JWT_SECRET');
$webhookSecret = agency_env_required('WEBHOOK_SECRET');
$apiBaseUrl = rtrim((string)(getenv('APP_BASE_URL') ?: 'https://smspro-api.nolacrm.io'), '/');
$agencyAppUrl = rtrim((string)(getenv('AGENCY_APP_URL') ?: 'https://agency.nolasmspro.com'), '/');
$redirectUri = rtrim((string)(getenv('GHL_AGENCY_REDIRECT_URI') ?: 'https://smspro-api.nolacrm.io/oauth/agency-callback'));

if (!isset($_GET['code'])) {
    agency_render_error('No authorization code received from GHL.');
}
$code = (string)$_GET['code'];

$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'client_id' => $agencyClientId,
        'client_secret' => $agencyClientSecret,
        'grant_type' => 'authorization_code',
        'code' => $code,
        'user_type' => 'Company',
        'redirect_uri' => $redirectUri,
    ]),
    CURLOPT_HTTPHEADER => ['Accept: application/json', 'Version: 2021-07-28'],
]);
$exchangeResp = curl_exec($ch);
$exchangeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$companyData = json_decode((string)$exchangeResp, true);
if ($exchangeCode !== 200 || empty($companyData['access_token'])) {
    agency_render_error('Agency token exchange failed.', agency_redact_tokens($companyData ?: []));
}

$companyToken = (string)$companyData['access_token'];
$companyRefresh = $companyData['refresh_token'] ?? null;
$companyId = $companyData['companyId'] ?? null;
$expiresIn = (int)($companyData['expires_in'] ?? 86400);
$companyExpiresAt = time() + $expiresIn;
if (!$companyId) {
    agency_render_error('No companyId in token response.');
}

$companyName = '';
$companyRes = agency_curl_json("https://services.leadconnectorhq.com/companies/{$companyId}", $companyToken);
if ($companyRes['code'] === 200) {
    $companyName = (string)($companyRes['body']['company']['name'] ?? '');
}

$db = get_firestore();
$now = new DateTimeImmutable();

try {
    $db->collection('ghl_tokens')->document((string)$companyId)->set([
        'access_token' => $companyToken,
        'refresh_token' => $companyRefresh,
        'expires_at' => $companyExpiresAt,
        'client_id' => $agencyClientId,
        'appId' => $agencyClientId,
        'appType' => 'agency',
        'userType' => 'Company',
        'companyId' => $companyId,
        'agency_name' => $companyName,
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);
} catch (Exception $e) {
    agency_render_error('Failed to save agency token.');
}

$sessionRef = $db->collection('install_sessions')->newDocument();
$sessionId = $sessionRef->id();
$sessionRef->set([
    'session_id' => $sessionId,
    'company_id' => (string)$companyId,
    'company_name' => $companyName,
    'status' => 'pending',
    'progress' => ['total_locations' => 0, 'provisioned' => 0, 'failed' => 0],
    'errors' => [],
    'created_at' => new \Google\Cloud\Core\Timestamp($now),
    'updated_at' => new \Google\Cloud\Core\Timestamp($now),
    'expires_at' => new \Google\Cloud\Core\Timestamp((new DateTimeImmutable('+1 day'))),
    'idempotency_key' => hash('sha256', (string)$companyId . ':' . gmdate('Y-m-d')),
], ['merge' => true]);

$hasExistingAgency = false;
try {
    $ownerLock = $db->collection('company_owners')->document((string)$companyId)->snapshot();
    if ($ownerLock->exists()) {
        $hasExistingAgency = true;
    }

    $agencyUsers = $db->collection('agency_users')->where('company_id', '=', (string)$companyId)->limit(1)->documents();
    foreach ($agencyUsers as $doc) {
        if ($doc->exists()) {
            $hasExistingAgency = true;
            break;
        }
    }
    if (!$hasExistingAgency) {
        $legacy = $db->collection('users')
            ->where('company_id', '=', (string)$companyId)
            ->where('role', '=', 'agency')
            ->limit(1)
            ->documents();
        foreach ($legacy as $doc) {
            if ($doc->exists()) {
                $hasExistingAgency = true;
                break;
            }
        }
    }
} catch (Exception $e) {
    error_log('[GHL_AGENCY_CALLBACK] Could not check existing agency users.');
}

$installToken = jwt_sign([
    'type' => 'agency_install',
    'company_id' => (string)$companyId,
    'company_name' => $companyName,
    'session_id' => $sessionId,
], $jwtSecret, 900);

agency_trigger_provision_async($apiBaseUrl, $sessionId, $webhookSecret);

if ($hasExistingAgency) {
    $redirectUrl = $agencyAppUrl . '/login?welcome_back=1&install_token=' . urlencode($installToken) . '&session_id=' . urlencode($sessionId);
} else {
    $redirectUrl = $agencyAppUrl . '/register-from-install?install_token=' . urlencode($installToken);
}

header('Location: ' . $redirectUrl, true, 302);
exit;

