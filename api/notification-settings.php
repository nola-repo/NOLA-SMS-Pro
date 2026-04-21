<?php

/**
 * notification-settings.php — Notification Preferences API.
 *
 * GET  /api/notification-settings  — Retrieve preferences for this location
 * POST /api/notification-settings  — Upsert preferences for this location
 *
 * Firestore location: integrations/{locId}.notification_preferences
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

validate_api_request();

$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    $locId = get_ghl_location_id();
    if (!$locId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing location_id (X-GHL-Location-ID header required)']);
        exit;
    }

    // Defaults used when no preferences exist yet
    $defaults = [
        'deliveryReports'     => false,
        'lowBalanceAlert'     => true,
        'lowBalanceThreshold' => 50,
        'marketingEmails'     => false,
    ];

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);
    $docRef = $db->collection('integrations')->document($intDocId);

    // ── GET — read current preferences ──────────────────────────────────
    if ($method === 'GET') {
        $snap = $docRef->snapshot();
        $prefs = $defaults;

        if ($snap->exists()) {
            $data = $snap->data();
            if (isset($data['notification_preferences']) && is_array($data['notification_preferences'])) {
                $d = $data['notification_preferences'];
                $prefs = [
                    'deliveryReports'     => (bool) ($d['delivery_reports_enabled'] ?? $defaults['deliveryReports']),
                    'lowBalanceAlert'     => (bool) ($d['low_balance_alert_enabled'] ?? $defaults['lowBalanceAlert']),
                    'lowBalanceThreshold' => (int)  ($d['low_balance_threshold'] ?? $defaults['lowBalanceThreshold']),
                    'marketingEmails'     => (bool) ($d['marketing_emails_enabled'] ?? $defaults['marketingEmails']),
                ];
            }
        } 

        echo json_encode($prefs);
        exit;
    }

    // ── POST / PUT — upsert preferences ─────────────────────────────────
    if ($method === 'POST' || $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $now = new \DateTimeImmutable();
        
        $updatePrefs = [];
        // Map camelCase frontend keys → snake_case Firestore fields
        if (isset($input['deliveryReports'])) {
            $updatePrefs['delivery_reports_enabled'] = (bool) $input['deliveryReports'];
        }
        if (isset($input['lowBalanceAlert'])) {
            $updatePrefs['low_balance_alert_enabled'] = (bool) $input['lowBalanceAlert'];
        }
        if (isset($input['lowBalanceThreshold'])) {
            $updatePrefs['low_balance_threshold'] = max(0, (int) $input['lowBalanceThreshold']);
        }
        if (isset($input['marketingEmails'])) {
            $updatePrefs['marketing_emails_enabled'] = (bool) $input['marketingEmails'];
        }

        $docRef->set([
            'notification_preferences' => $updatePrefs,
            'updated_at' => new \Google\Cloud\Core\Timestamp($now)
        ], ['merge' => true]);

        echo json_encode([
            'success' => true,
            'message' => 'Notification settings updated',
        ]);
        exit;
    }

    // ── Unsupported method ──────────────────────────────────────────────
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to process notification settings request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
