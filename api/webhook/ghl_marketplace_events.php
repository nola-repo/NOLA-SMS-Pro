<?php

/**
 * ghl_marketplace_events.php — GoHighLevel Marketplace AppInstall / AppUninstall.
 *
 * Dedicated URL (optional if Default webhook URL already points at send_sms):
 *   https://YOUR_CLOUD_RUN_HOST/webhook/ghl_marketplace_events?secret=YOUR_WEBHOOK_SECRET
 *
 * GHL often delivers AppUninstall to the **Default webhook URL** (e.g. /webhook/send_sms);
 * send_sms.php detects INSTALL/UNINSTALL and delegates here via install_handle_marketplace_webhook().
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require __DIR__ . '/../install_helpers.php';
$config = require __DIR__ . '/config.php';

validate_api_request();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!install_is_marketplace_lifecycle_payload($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Expected INSTALL or UNINSTALL marketplace event']);
    exit;
}

try {
    $db = get_firestore();
    $result = install_handle_marketplace_webhook($db, $payload, $config);
    http_response_code((int)$result['status']);
    echo json_encode($result['body']);
} catch (Throwable $e) {
    error_log('[ghl_marketplace_events] error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Webhook processing failed']);
}
