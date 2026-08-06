<?php
/**
 * api/connectivity_health_check.php
 * Route: GET /api/connectivity-health-check
 *
 * Scheduled health check endpoint — call this every 30 minutes via a cron job
 * or Google Cloud Scheduler. Runs full network diagnostics and saves the result
 * to Firestore → connectivity_incidents so you have a proactive history of
 * network health BEFORE users start reporting issues.
 *
 * Security: requires the same X-Diag-Key header or ?key= query param.
 * Recommended Cloud Scheduler setup:
 *   Target: https://smspro-api.nolacrm.io/api/connectivity-health-check
 *   Schedule: every 30 minutes  →  30 * * * *
 *   Header: X-Diag-Key: nola_diag_2026
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/services/ConnectivityMonitor.php';

// ── Auth ───────────────────────────────────────────────────────────────────────
$ACCESS_KEY   = 'nola_diag_2026';
$headers      = function_exists('getallheaders') ? getallheaders() : [];
$headerKey    = $_SERVER['HTTP_X_DIAG_KEY'] ?? ($headers['X-Diag-Key'] ?? ($headers['x-diag-key'] ?? ''));
$providedKey  = $_GET['key'] ?? $headerKey;
$userAgent    = $_SERVER['HTTP_USER_AGENT'] ?? ($headers['User-Agent'] ?? ($headers['user-agent'] ?? ''));
$isCloudScheduler = !empty($_SERVER['HTTP_X_CLOUDSCHEDULER_SCHEDULENAME']) || (stripos($userAgent, 'Cloud-Scheduler') !== false);

if ($providedKey !== $ACCESS_KEY && !$isCloudScheduler) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// ── Run diagnostics ────────────────────────────────────────────────────────────
$db     = get_firestore();
$report = ConnectivityMonitor::runAndSave('scheduled', '', 'scheduled_health_check', $db);

// ── Respond ────────────────────────────────────────────────────────────────────
http_response_code(200);
echo json_encode([
    'status'         => 'ok',
    'overall_status' => $report['summary']['overall_status'],
    'verdict'        => $report['summary']['verdict'],
    'total_timeouts' => $report['summary']['total_timeouts'],
    'total_failures' => $report['summary']['total_failures'],
    'generated_at'   => $report['generated_at'],
    'duration_ms'    => $report['duration_ms'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
