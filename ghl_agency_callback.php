<?php
/**
 * GHL Agency OAuth Callback
 * Route: /oauth/agency-callback  (htaccess → this file)
 * App:   Agency app  client_id = GHL_AGENCY_CLIENT_ID
 *
 * Flow:
 *  1. GHL sends ?code= here after agency bulk-install
 *  2. Exchange for a Company-level token
 *  3. Call /oauth/locationToken for EVERY location under the company
 *  4. Save each location token to ghl_tokens/{locationId}
 *  5. Show success page
 *
 * GHL Marketplace — Redirect URI to register for the AGENCY app:
 *   https://smspro-api.nolacrm.io/oauth/agency-callback
 */

require __DIR__ . '/api/webhook/firestore_client.php';

// ─── Helpers ──────────────────────────────────────────────────────────────────
function agency_render_error(string $msg, array $details = []): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    $safe    = htmlspecialchars($msg);
    $detHtml = '';
    if ($details) {
        $detHtml = '<pre style="font-size:11px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:10px;max-height:200px;overflow:auto;text-align:left;">'
            . htmlspecialchars(json_encode($details, JSON_PRETTY_PRINT)) . '</pre>';
    }
    echo <<<HTML
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>Agency Install Failed</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;700;800&display=swap" rel="stylesheet">
<style>
body{font-family:'Poppins',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;background:#f9fafb;margin:0;overflow:hidden;position:relative;}
.blob{position:fixed;border-radius:50%;background:#2b83fa;filter:blur(120px);opacity:.15;pointer-events:none;}
.blob-tl{top:-10%;left:-10%;width:50vw;height:50vw;}
.blob-br{bottom:-10%;right:-10%;width:50vw;height:50vw;}
.card{background:rgba(255,255,255,.75);backdrop-filter:blur(24px);border:1px solid rgba(255,255,255,.3);border-radius:24px;padding:40px 32px;max-width:440px;width:100%;box-shadow:0 8px 32px rgba(0,0,0,.06);text-align:center;position:relative;z-index:1;}
h1{font-size:24px;font-weight:800;color:#dc2626;margin-bottom:8px;}p{color:#555;margin-bottom:16px;}</style></head>
<body><div class="blob blob-tl"></div><div class="blob blob-br"></div><div class="card"><h1>Installation Failed</h1><p>{$safe}</p>{$detHtml}
<a href="https://marketplace.leadconnectorhq.com/developer-portal" style="display:inline-block;margin-top:16px;padding:12px 28px;background:#2b83fa;color:#fff;border-radius:99px;font-weight:700;text-decoration:none;">Back to Marketplace</a>
</div></body></html>
HTML;
    exit;
}

function agency_curl_json(string $url, string $token, string $method = 'GET', ?array $body = null): array
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
        'Version: 2021-07-28',
    ];
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($resp, true) ?? [], 'raw' => $resp];
}

// ─── Config ────────────────────────────────────────────────────────────────────
$agencyClientId     = getenv('GHL_AGENCY_CLIENT_ID')     ?: '69d31f33b3071b25dbcc5656-mnqxvtt3';
$agencyClientSecret = getenv('GHL_AGENCY_CLIENT_SECRET') ?: '64b90a28-8cb1-4a44-8212-0a8f3f255322';
$redirectUri        = 'https://smspro-api.nolacrm.io/oauth/agency-callback'; // HARDCODED — must match GHL Marketplace

if (!$agencyClientId || !$agencyClientSecret) {
    agency_render_error('Agency GHL credentials are not configured on the server.');
}

if (!isset($_GET['code'])) {
    agency_render_error('No authorization code received from GHL.');
}

$code = $_GET['code'];

// ─── Step 1: Exchange Code → Company Token ─────────────────────────────────────
$ch = curl_init('https://services.leadconnectorhq.com/oauth/token');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'client_id'     => $agencyClientId,
    'client_secret' => $agencyClientSecret,
    'grant_type'    => 'authorization_code',
    'code'          => $code,
    'user_type'     => 'Company',
    'redirect_uri'  => $redirectUri,
]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Version: 2021-07-28']);
$exchangeResp = curl_exec($ch);
$exchangeCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$companyData = json_decode($exchangeResp, true);
if ($exchangeCode !== 200 || empty($companyData['access_token'])) {
    agency_render_error('Agency token exchange failed.', $companyData ?: ['raw' => $exchangeResp]);
}

$companyToken     = $companyData['access_token'];
$companyRefresh   = $companyData['refresh_token'] ?? null;
$companyId        = $companyData['companyId'] ?? null;
$expiresIn        = (int)($companyData['expires_in'] ?? 86400);
$companyExpiresAt = time() + $expiresIn;

if (!$companyId) {
    agency_render_error('No companyId in token response.', $companyData);
}

error_log("[GHL_AGENCY_CALLBACK] Company token received for companyId={$companyId}");

// ─── Step 2: Fetch Company Name ────────────────────────────────────────────────
$companyName = '';
$companyRes  = agency_curl_json("https://services.leadconnectorhq.com/companies/{$companyId}", $companyToken);
if ($companyRes['code'] === 200) {
    $companyName = $companyRes['body']['company']['name'] ?? '';
}

// ─── Step 3: Save Company-Level Token to Firestore ────────────────────────────
$db  = get_firestore();
$now = new DateTimeImmutable();

try {
    $db->collection('ghl_tokens')->document($companyId)->set([
        'access_token'  => $companyToken,
        'refresh_token' => $companyRefresh,
        'expires_at'    => $companyExpiresAt,
        'client_id'     => $agencyClientId,
        'appId'         => $agencyClientId,
        'appType'       => 'agency',
        'userType'      => 'Company',
        'companyId'     => $companyId,
        'agency_name'   => $companyName,
        'raw'           => $companyData,
        'updated_at'    => new \Google\Cloud\Core\Timestamp($now),
    ], ['merge' => true]);
} catch (Exception $e) {
    agency_render_error('Failed to save agency token: ' . $e->getMessage());
}

// ─── Step 4: Fetch All Locations For This Company ─────────────────────────────
$allLocationIds = [];

// Try from token response first (bulk install may include a locations array)
if (!empty($companyData['locations']) && is_array($companyData['locations'])) {
    foreach ($companyData['locations'] as $loc) {
        $lid = $loc['id'] ?? $loc['locationId'] ?? null;
        if ($lid) $allLocationIds[] = $lid;
    }
}

// Fallback: query GHL API for all locations under this company
if (empty($allLocationIds)) {
    $skip  = 0;
    $limit = 100;
    do {
        $locRes  = agency_curl_json(
            "https://services.leadconnectorhq.com/locations/search?companyId={$companyId}&skip={$skip}&limit={$limit}",
            $companyToken
        );
        $fetched = $locRes['body']['locations'] ?? [];
        foreach ($fetched as $loc) {
            $lid = $loc['id'] ?? null;
            if ($lid) $allLocationIds[] = $lid;
        }
        $skip += $limit;
    } while (count($fetched) === $limit && count($allLocationIds) < 500);
}

error_log("[GHL_AGENCY_CALLBACK] Found " . count($allLocationIds) . " locations for company {$companyId}");

// ─── Step 5: Exchange Company Token → Per-Location Tokens ─────────────────────
$provisioned = 0;
$failed      = 0;

foreach ($allLocationIds as $locId) {
    try {
        $ltCh = curl_init('https://services.leadconnectorhq.com/oauth/locationToken');
        curl_setopt_array($ltCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query(['companyId' => $companyId, 'locationId' => $locId]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $companyToken,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $ltRespRaw = curl_exec($ltCh);
        $ltCode    = curl_getinfo($ltCh, CURLINFO_HTTP_CODE);
        curl_close($ltCh);
        $ltRes = ['code' => $ltCode, 'body' => json_decode($ltRespRaw, true), 'raw' => $ltRespRaw];

        if ($ltRes['code'] === 200 && !empty($ltRes['body']['access_token'])) {
            $ltToken     = $ltRes['body']['access_token'];
            $ltExpiresAt = time() + (int)($ltRes['body']['expires_in'] ?? 86400);

            // Fetch location name
            $locNameRes = agency_curl_json("https://services.leadconnectorhq.com/locations/{$locId}", $ltToken);
            $locName    = $locNameRes['body']['location']['name'] ?? '';

            $ts = new DateTimeImmutable();

            // Save to ghl_tokens — GhlClient.php reads access_token from here
            $db->collection('ghl_tokens')->document($locId)->set([
                'access_token'          => $ltToken,
                'refresh_token'         => $companyRefresh,
                'expires_at'            => $ltExpiresAt,
                'client_id'             => $agencyClientId,
                'appId'                 => $agencyClientId,
                'appType'               => 'agency',
                'userType'              => 'Location',
                'location_id'           => $locId,
                'location_name'         => $locName,
                'companyId'             => $companyId,
                'is_live'               => true,
                'toggle_enabled'        => true,
                'provisioned_from_bulk' => true,
                'updated_at'            => new \Google\Cloud\Core\Timestamp($ts),
            ], ['merge' => true]);

            // Save to integrations (credits / sender ID tracking)
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locId);
            $intRef   = $db->collection('integrations')->document($intDocId);
            $intSnap  = $intRef->snapshot();
            if (!$intSnap->exists()) {
                $intRef->set([
                    'location_id'           => $locId,
                    'location_name'         => $locName,
                    'companyId'             => $companyId,
                    'free_credits_total'    => 10,
                    'free_usage_count'      => 0,
                    'credit_balance'        => 0,
                    'system_default_sender' => 'NOLASMSPro',
                    'installed_at'          => new \Google\Cloud\Core\Timestamp($ts),
                    'updated_at'            => new \Google\Cloud\Core\Timestamp($ts),
                ]);
            } else {
                $intRef->set([
                    'access_token'  => $ltToken,
                    'expires_at'    => $ltExpiresAt,
                    'location_name' => $locName,
                    'updated_at'    => new \Google\Cloud\Core\Timestamp($ts),
                ], ['merge' => true]);
            }

            $provisioned++;
            error_log("[GHL_AGENCY_CALLBACK] Provisioned location token for {$locId} ({$locName})");
        } else {
            $failed++;
            error_log("[GHL_AGENCY_CALLBACK] locationToken failed for {$locId}: HTTP {$ltRes['code']} — {$ltRes['raw']}");
        }
    } catch (Exception $e) {
        $failed++;
        error_log("[GHL_AGENCY_CALLBACK] Exception for {$locId}: " . $e->getMessage());
    }
}

// ─── Redirect Logic (replaces static success page) ────────────────────────────

require_once __DIR__ . '/api/jwt_helper.php';

$jwtSecret      = getenv('JWT_SECRET') ?: 'nola_sms_pro_jwt_secret_change_in_production';
$agencyAppUrl   = 'https://agency.nolasmspro.com';

// Check if an agency user is already registered for this company_id
$hasExistingAgency = false;
try {
    $agencyUsers = $db->collection('users')
        ->where('company_id', '=', $companyId)
        ->where('role', '=', 'agency')
        ->limit(1)
        ->documents();

    foreach ($agencyUsers as $doc) {
        if ($doc->exists()) {
            $hasExistingAgency = true;
            break;
        }
    }
} catch (Exception $e) {
    error_log('[GHL_AGENCY_CALLBACK] Could not check existing agency users: ' . $e->getMessage());
}

if ($hasExistingAgency) {
    // ── Re-install: redirect to login with welcome-back banner ──
    $redirectUrl = $agencyAppUrl . '/login?welcome_back=1';
    error_log("[GHL_AGENCY_CALLBACK] Re-install for company {$companyId} — redirecting to welcome-back login.");
} else {
    // ── First install: generate install token → React agency registration ──
    $installToken = jwt_sign([
        'type'         => 'agency_install',
        'company_id'   => $companyId,
        'company_name' => $companyName ?: '',
    ], $jwtSecret, 900); // 15 minutes

    $redirectUrl = $agencyAppUrl . '/register-from-install?install_token=' . urlencode($installToken);
    error_log("[GHL_AGENCY_CALLBACK] First install for company {$companyId} — redirecting to registration.");
}

header('Location: ' . $redirectUrl, true, 302);
exit;

