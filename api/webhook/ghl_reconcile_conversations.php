<?php

/**
 * Incremental reconciliation for GHL-native messages missed by webhook delivery.
 *
 * Suggested scheduler URL:
 *   /webhook/ghl_reconcile_conversations?secret=WEBHOOK_SECRET&since_minutes=15&limit=50
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/GhlNativeMessageSyncService.php';

validate_api_request();

if (!in_array(($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$locationId = $_GET['location_id']
    ?? $_GET['locationId']
    ?? $payload['location_id']
    ?? $payload['locationId']
    ?? null;
$limit = (int)($_GET['limit'] ?? $payload['limit'] ?? 25);
$sinceMinutes = (int)($_GET['since_minutes'] ?? $payload['since_minutes'] ?? 15);

try {
    $db = get_firestore();
    $result = GhlNativeMessageSyncService::reconcile(
        $db,
        $locationId ? (string)$locationId : null,
        $limit,
        $sinceMinutes
    );

    http_response_code(!empty($result['success']) ? 200 : 207);
    echo json_encode($result);
} catch (\Throwable $e) {
    error_log('[ghl_reconcile_conversations] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'GHL reconciliation failed']);
}
