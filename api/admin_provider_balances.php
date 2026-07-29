<?php
/**
 * api/admin_provider_balances.php
 * Route: GET /api/admin/provider-balances
 *
 * Returns the live credit balance for BOTH Semaphore and UniSMS gateway accounts,
 * regardless of which provider is currently active.
 *
 * Requested by: Norwin Lacson (Owner) — July 28, 2026
 * Purpose: Admin monitoring of SMS gateway credits to prevent silent delivery failures.
 *
 * Auth:  super_admin only
 * Cache: 60-second TTL via NolaCache
 * Note:  Always returns HTTP 200. Individual provider errors are embedded in the
 *        response body so the frontend can show partial data gracefully.
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/services/SmsGatewayService.php';
require_once __DIR__ . '/cache_helper.php';

// Only super_admin may view raw provider credentials / balances
$claims = require_secure_admin_auth(['super_admin']);

// ── Thresholds (agreed 2026-07-29) ─────────────────────────────────────────
// Semaphore  : warn < 1,000 | critical < 300
// UniSMS     : warn < 200   | critical < 50
const SEMAPHORE_WARN     = 1000;
const SEMAPHORE_CRITICAL = 300;
const UNISMS_WARN        = 200;
const UNISMS_CRITICAL    = 50;
const CACHE_TTL          = 60; // seconds

$cacheKey = 'admin_provider_balances';

// ── Cache hit ───────────────────────────────────────────────────────────────
$cached = NolaCache::get($cacheKey);
if ($cached !== null) {
    echo json_encode($cached);
    exit;
}

// ── Resolve active provider name ────────────────────────────────────────────
$activeProviderName = 'semaphore';
try {
    $gateway = new SmsGatewayService();
    $activeProviderName = $gateway->getProviderName();
} catch (\Throwable $e) {
    error_log('[admin_provider_balances] SmsGatewayService init failed: ' . $e->getMessage());
}

// ── Helper: build a provider result array ───────────────────────────────────
function build_provider_result(
    string $providerKey,
    string $providerLabel,
    bool   $isActive,
    int    $warnThreshold,
    int    $criticalThreshold,
    array  $accCheck,
    ?string $errorMsg
): array {
    $credits = (int)($accCheck['credits'] ?? 0);
    $status  = (string)($accCheck['status'] ?? ($errorMsg ? 'error' : 'inactive'));

    $result = [
        'name'        => $providerLabel,
        'status'      => $status,
        'credits'     => $credits,
        'configured'  => $status === 'active',
        'is_active'   => $isActive,
        'warning'     => $credits < $warnThreshold && $credits >= $criticalThreshold && $status === 'active',
        'critical'    => $credits < $criticalThreshold && $status === 'active',
        'error'       => $errorMsg,
    ];

    // UniSMS-specific extras
    if ($providerKey === 'unisms') {
        $result['email']      = $accCheck['email'] ?? null;
        $result['sid_tokens'] = isset($accCheck['sid_tokens']) ? (int)$accCheck['sid_tokens'] : null;
    }

    return $result;
}

// ── Fetch Semaphore balance ──────────────────────────────────────────────────
$semResult = [];
$semError  = null;
try {
    $semProvider = $gateway->getProviderInstance('semaphore');
    $semCheck    = $semProvider->checkAccount();
    $semResult   = $semCheck;
} catch (\Throwable $e) {
    $semError  = $e->getMessage();
    $semResult = ['status' => 'error', 'credits' => 0];
    error_log('[admin_provider_balances] Semaphore checkAccount failed: ' . $semError);
}

// ── Fetch UniSMS balance ─────────────────────────────────────────────────────
$uniResult = [];
$uniError  = null;
try {
    $uniProvider = $gateway->getProviderInstance('unisms');
    $uniCheck    = $uniProvider->checkAccount();
    $uniResult   = $uniCheck;
} catch (\Throwable $e) {
    $uniError  = $e->getMessage();
    $uniResult = ['status' => 'error', 'credits' => 0];
    error_log('[admin_provider_balances] UniSMS checkAccount failed: ' . $uniError);
}

// ── Determine is_active flags ────────────────────────────────────────────────
// auto_failover uses Semaphore as primary, so treat it as Semaphore-active
$semIsActive = in_array($activeProviderName, ['semaphore', 'auto_failover'], true);
$uniIsActive = $activeProviderName === 'unisms';

// ── Build response ───────────────────────────────────────────────────────────
$responsePayload = [
    'status'          => 'success',
    'fetched_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    'active_provider' => $activeProviderName,
    'providers'       => [
        'semaphore' => build_provider_result(
            'semaphore',
            'Semaphore',
            $semIsActive,
            SEMAPHORE_WARN,
            SEMAPHORE_CRITICAL,
            $semResult,
            $semError
        ),
        'unisms' => build_provider_result(
            'unisms',
            'UniSMS',
            $uniIsActive,
            UNISMS_WARN,
            UNISMS_CRITICAL,
            $uniResult,
            $uniError
        ),
    ],
];

// ── Cache and respond ────────────────────────────────────────────────────────
NolaCache::set($cacheKey, $responsePayload, CACHE_TTL);
echo json_encode($responsePayload);
exit;
