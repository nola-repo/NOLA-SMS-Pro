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
 * Cache: 60-second TTL via NolaCache (only when live API data is confirmed)
 * Note:  Always returns HTTP 200. Individual provider errors are embedded in the
 *        response body so the frontend can show partial data gracefully.
 *
 * Response envelope additions (v2 — 2026-08-12):
 *   is_stale       bool   — true when any provider value came from cache/LKG rather than live API
 *   data_quality   array  — per-provider { fetched_via: string, is_live: bool }
 *   Frontend teams: display a "Last known value" chip when is_stale === true.
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/../services/SmsGatewayService.php';
require_once __DIR__ . '/../services/SemaphoreBalanceFetcher.php';
require_once __DIR__ . '/../cache_helper.php';

// Only super_admin may view raw provider credentials / balances
$claims = require_secure_admin_auth(['super_admin']);

// ── Thresholds (agreed 2026-07-29) ─────────────────────────────────────────
// Semaphore  : warn < 1,000 | critical < 300
// UniSMS     : warn < 200   | critical < 50
const SEMAPHORE_WARN     = 1000;
const SEMAPHORE_CRITICAL = 300;
const UNISMS_WARN        = 200;
const UNISMS_CRITICAL    = 50;
const CACHE_TTL          = 60; // seconds — only applied when data is confirmed live

$cacheKey    = 'admin_provider_balances';
$bypassCache = !empty($_GET['refresh']) || !empty($_GET['bypass_cache']) || !empty($_GET['clear_cache']);

// ── Cache hit ───────────────────────────────────────────────────────────────
if (!$bypassCache) {
    $cached = NolaCache::get($cacheKey);
    if ($cached !== null) {
        echo json_encode($cached);
        exit;
    }
} elseif (!empty($_GET['clear_cache'])) {
    NolaCache::delete($cacheKey);
}

// ── Resolve active provider name ────────────────────────────────────────────
$activeProviderName = 'semaphore';
try {
    $gateway = new SmsGatewayService();
    $activeProviderName = $gateway->getProviderName();
} catch (\Throwable $e) {
    error_log('[admin_provider_balances] SmsGatewayService init failed: ' . $e->getMessage());
}

$db      = get_firestore();
$fetcher = new SemaphoreBalanceFetcher();
$summary = $bypassCache
    ? $fetcher->getSystemProviderSummary()
    : $fetcher->getLightweightDashboardSummary($db, true);

$semIsActive = in_array($activeProviderName, ['semaphore', 'auto_failover'], true);
$uniIsActive = $activeProviderName === 'unisms';

$semCredits    = (int)($summary['semaphore']['total_credits'] ?? 0);
$uniCredits    = (int)($summary['unisms']['total_credits'] ?? 0);
$semFetchedVia = (string)($summary['semaphore']['fetched_via'] ?? 'none');
$uniFetchedVia = (string)($summary['unisms']['fetched_via'] ?? 'none');

$summary['semaphore']['is_active']  = $semIsActive;
$summary['semaphore']['warning']    = $semCredits < SEMAPHORE_WARN && $semCredits >= SEMAPHORE_CRITICAL && $summary['semaphore']['status'] === 'active';
$summary['semaphore']['critical']   = $semCredits < SEMAPHORE_CRITICAL && $summary['semaphore']['status'] === 'active';
// configured + error are required by the ProviderBalance TypeScript interface;
// without them the frontend card renders as "Not Configured" (gray/dimmed).
$summary['semaphore']['configured'] = $summary['semaphore']['status'] === 'active';
$summary['semaphore']['error']      = null;

$summary['unisms']['is_active']    = $uniIsActive;
$summary['unisms']['warning']      = $uniCredits < UNISMS_WARN && $uniCredits >= UNISMS_CRITICAL && $summary['unisms']['status'] === 'active';
$summary['unisms']['critical']     = $uniCredits < UNISMS_CRITICAL && $summary['unisms']['status'] === 'active';
$summary['unisms']['configured']   = $summary['unisms']['status'] === 'active';
$summary['unisms']['error']        = null;

// ── Data quality assessment ─────────────────────────────────────────────────
// A value is "live" only when it came directly from the provider API.
// Any other source (Redis cache, Firestore LKG, subaccount field) is stale.
$semIsLive = ($semFetchedVia === 'live_api');
$uniIsLive = ($uniFetchedVia === 'live_api');
$isStale   = !$semIsLive || !$uniIsLive;

$dataQuality = [
    'semaphore' => ['fetched_via' => $semFetchedVia, 'is_live' => $semIsLive],
    'unisms'    => ['fetched_via' => $uniFetchedVia, 'is_live' => $uniIsLive],
];

// ── Build response ───────────────────────────────────────────────────────────
$responsePayload = [
    'status'          => 'success',
    'fetched_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    'active_provider' => $activeProviderName,
    'is_stale'        => $isStale,
    'data_quality'    => $dataQuality,
    'summary'         => $summary,
    'providers'       => $summary,
];

// ── Cache strategy ───────────────────────────────────────────────────────────
// Only cache when at least one provider returned live API data.
// Never cache a pure timeout result — the next poll should always retry the API.
// If data came from Redis/Firestore fallback, use a 30-second TTL so we retry
// the live API soon without hammering it on every single request.
if ($semIsLive || $uniIsLive) {
    // Confirmed live — safe to cache for the full interval
    NolaCache::set($cacheKey, $responsePayload, CACHE_TTL);
} elseif ($semCredits > 0 || $uniCredits > 0) {
    // Stale but non-zero fallback data — short TTL, retry live soon
    NolaCache::set($cacheKey, $responsePayload, 30);
}
// else: timeout with no fallback data at all — do NOT cache; every request retries

echo json_encode($responsePayload);
exit;
