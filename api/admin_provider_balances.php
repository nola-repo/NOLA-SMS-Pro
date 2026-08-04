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
require_once __DIR__ . '/services/SemaphoreBalanceFetcher.php';
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

$db = get_firestore();
$fetcher = new SemaphoreBalanceFetcher();
$summary = $fetcher->getDashboardSummary($db);

$semIsActive = in_array($activeProviderName, ['semaphore', 'auto_failover'], true);
$uniIsActive = $activeProviderName === 'unisms';

$semCredits = (int)($summary['semaphore']['total_credits'] ?? 0);
$uniCredits = (int)($summary['unisms']['total_credits'] ?? 0);

$summary['semaphore']['is_active'] = $semIsActive;
$summary['semaphore']['warning']   = $semCredits < SEMAPHORE_WARN && $semCredits >= SEMAPHORE_CRITICAL && $summary['semaphore']['status'] === 'active';
$summary['semaphore']['critical']  = $semCredits < SEMAPHORE_CRITICAL && $summary['semaphore']['status'] === 'active';

$summary['unisms']['is_active']    = $uniIsActive;
$summary['unisms']['warning']      = $uniCredits < UNISMS_WARN && $uniCredits >= UNISMS_CRITICAL && $summary['unisms']['status'] === 'active';
$summary['unisms']['critical']     = $uniCredits < UNISMS_CRITICAL && $summary['unisms']['status'] === 'active';

// ── Build response ───────────────────────────────────────────────────────────
$responsePayload = [
    'status'          => 'success',
    'fetched_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
    'active_provider' => $activeProviderName,
    'summary'         => $summary,
    'providers'       => $summary,
];

// ── Cache and respond ────────────────────────────────────────────────────────
NolaCache::set($cacheKey, $responsePayload, CACHE_TTL);
echo json_encode($responsePayload);
exit;
