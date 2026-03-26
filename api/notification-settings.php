<?php

/**
 * notification-settings.php — Notification Preferences API.
 *
 * GET  /api/notification-settings  — Retrieve preferences for this location
 * POST /api/notification-settings  — Upsert preferences for this location
 *
 * Firestore collection: notification_settings (doc ID = locationId)
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

    $docRef = $db->collection('notification_settings')->document($locId);

    // ── GET — read current preferences ──────────────────────────────────
    if ($method === 'GET') {
        $snap = $docRef->snapshot();

        if ($snap->exists()) {
            $d = $snap->data();
            $prefs = [
                'deliveryReports'     => (bool) ($d['delivery_reports_enabled'] ?? $defaults['deliveryReports']),
                'lowBalanceAlert'     => (bool) ($d['low_balance_alert_enabled'] ?? $defaults['lowBalanceAlert']),
                'lowBalanceThreshold' => (int)  ($d['low_balance_threshold'] ?? $defaults['lowBalanceThreshold']),
                'marketingEmails'     => (bool) ($d['marketing_emails_enabled'] ?? $defaults['marketingEmails']),
            ];
        } else {
            $prefs = $defaults;
        }

        echo json_encode($prefs);
        exit;
    }

    // ── POST / PUT — upsert preferences ─────────────────────────────────
    if ($method === 'POST' || $method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];

        $now = new \DateTimeImmutable();
        $updateData = [
            'location_id' => $locId,
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now),
        ];

        // Map camelCase frontend keys → snake_case Firestore fields
        if (isset($input['deliveryReports'])) {
            $updateData['delivery_reports_enabled'] = (bool) $input['deliveryReports'];
        }
        if (isset($input['lowBalanceAlert'])) {
            $updateData['low_balance_alert_enabled'] = (bool) $input['lowBalanceAlert'];
        }
        if (isset($input['lowBalanceThreshold'])) {
            $updateData['low_balance_threshold'] = max(0, (int) $input['lowBalanceThreshold']);
        }
        if (isset($input['marketingEmails'])) {
            $updateData['marketing_emails_enabled'] = (bool) $input['marketingEmails'];
        }

        $docRef->set($updateData, ['merge' => true]);

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
