<?php

/**
 * Ingests GHL-native conversation message events into NOLA's canonical sync layer.
 *
 * Public webhook URL:
 *   /webhooks/ghl/conversation-message?secret=WEBHOOK_SECRET
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/GhlNativeMessageSyncService.php';

$config = require __DIR__ . '/config.php';

validate_api_request();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
$signature = GhlNativeMessageSyncService::verifyOptionalSignature($config, $raw);
if (empty($signature['success'])) {
    error_log('[ghl_conversation_message] signature verification failed');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Invalid webhook signature']);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

if (!is_array($payload) || empty($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

try {
    $db = get_firestore();
    $result = GhlNativeMessageSyncService::recordConversationMessagePayload($db, $payload, 'ghl_native_webhook');

    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => 'GHL conversation message synced',
        'data' => $result,
        'signature' => [
            'verified' => (bool)($signature['verified'] ?? false),
            'reason' => $signature['reason'] ?? null,
        ],
    ]);
} catch (\InvalidArgumentException $e) {
    error_log('[ghl_conversation_message] bad request: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    error_log('[ghl_conversation_message] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to sync GHL conversation message']);
}
