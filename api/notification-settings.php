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
        'deliveryReports'        => false,
        'lowBalanceAlert'        => true,
        'lowBalanceThreshold'    => 50,
        'marketingEmails'        => false,
        'ghlWorkflowSyncEnabled' => false,
        'ghlAlertContactId'      => null,
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
                    'deliveryReports'        => (bool) ($d['delivery_reports_enabled'] ?? $defaults['deliveryReports']),
                    'lowBalanceAlert'        => (bool) ($d['low_balance_alert_enabled'] ?? $defaults['lowBalanceAlert']),
                    'lowBalanceThreshold'    => (int)  ($d['low_balance_threshold'] ?? $defaults['lowBalanceThreshold']),
                    'marketingEmails'        => (bool) ($d['marketing_emails_enabled'] ?? $defaults['marketingEmails']),
                    'ghlWorkflowSyncEnabled' => (bool) ($d['ghl_workflow_sync_enabled'] ?? $defaults['ghlWorkflowSyncEnabled']),
                    'ghlAlertContactId'      => $d['ghl_alert_contact_id'] ?? null,
                ];
            }
        } 

        // Resolve registered email server-side
        $userEmail = null;
        try {
            // 1. Query users where active_location_id matches locId
            $userQuery = $db->collection('users')
                ->where('active_location_id', '=', (string)$locId)
                ->limit(1)
                ->documents();

            foreach ($userQuery as $doc) {
                if ($doc->exists()) {
                    $userData = $doc->data();
                    $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
                    break;
                }
            }

            // 2. Fallback: subaccounts collectionGroup
            if ($userEmail === null || $userEmail === '') {
                $subQuery = $db->collectionGroup('subaccounts')
                    ->where('location_id', '=', (string)$locId)
                    ->limit(1)
                    ->documents();

                foreach ($subQuery as $subDoc) {
                    if ($subDoc->exists()) {
                        $parentUserRef = $subDoc->reference()->parent()->parent();
                        if ($parentUserRef !== null) {
                            $parentSnap = $parentUserRef->snapshot();
                            if ($parentSnap->exists()) {
                                $userData = $parentSnap->data();
                                $userEmail = isset($userData['email']) ? trim((string)$userData['email']) : null;
                            }
                        }
                        break;
                    }
                }
            }

            // 3. Fallback: install_linked_account_for_location
            if ($userEmail === null || $userEmail === '') {
                require_once __DIR__ . '/install_helpers.php';
                $linked = install_linked_account_for_location($db, (string)$locId, false);
                if ($linked !== null) {
                    $userEmail = $linked['email'] !== '' ? $linked['email'] : null;
                }
            }

            // 4. Fallback: ghl_tokens
            if ($userEmail === null || $userEmail === '') {
                $tokenSnap = $db->collection('ghl_tokens')->document((string)$locId)->snapshot();
                if ($tokenSnap->exists()) {
                    $tokenData = $tokenSnap->data();
                    $userEmail = $tokenData['email'] ?? $tokenData['user_email'] ?? null;
                }
            }

            // 5. Fallback: integrations
            if ($userEmail === null || $userEmail === '') {
                if ($snap->exists()) {
                    $intData = $snap->data();
                    $userEmail = $intData['email'] ?? $intData['user_email'] ?? null;
                }
            }
        } catch (\Throwable $e) {
            error_log("[notification-settings] Failed to fetch user email: " . $e->getMessage());
        }

        $prefs['alertEmail'] = $userEmail ?: '';
        if (!isset($prefs['ghlAlertContactId'])) {
            $prefs['ghlAlertContactId'] = null;
        }

        echo json_encode([
            'success' => true,
            'data'    => $prefs
        ], JSON_PRETTY_PRINT);
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
        if (isset($input['ghlWorkflowSyncEnabled'])) {
            $updatePrefs['ghl_workflow_sync_enabled'] = (bool) $input['ghlWorkflowSyncEnabled'];
        }

        // Read existing notification preferences first to preserve system keys (e.g. ghl_alert_contact_id)
        $snap = $docRef->snapshot();
        $existingPrefs = [];
        if ($snap->exists()) {
            $data = $snap->data();
            $existingPrefs = $data['notification_preferences'] ?? [];
        }
        $newPrefs = array_merge($existingPrefs, $updatePrefs);

        $docRef->set([
            'notification_preferences' => $newPrefs,
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
