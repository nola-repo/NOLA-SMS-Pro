<?php

/**
 * auto_close_tickets.php — Cloud Scheduler cron (runs every hour).
 *
 * Auto-closes tickets that have been in 'resolved' status for >= 72 hours.
 * Schedule this in Google Cloud Scheduler or the existing cron setup:
 *   Endpoint: GET /api/webhook/auto_close_tickets.php?secret=WEBHOOK_SECRET
 *   Schedule: every 1 hour
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';

validate_api_request();

$db = get_firestore();

$cutoff = new \DateTime();
$cutoff->modify('-72 hours');
$cutoffTs = new \Google\Cloud\Core\Timestamp($cutoff);

$closed = 0;

try {
    // Find all resolved tickets where resolved_at is older than 72 hours
    $q = $db->collection('support_tickets')
        ->where('status', '==', 'resolved')
        ->where('resolved_at', '<', $cutoffTs)
        ->limit(100);

    foreach ($q->documents() as $doc) {
        if (!$doc->exists()) continue;

        $doc->reference()->update([
            ['path' => 'status',     'value' => 'closed'],
            ['path' => 'updated_at', 'value' => new \Google\Cloud\Core\Timestamp(new \DateTime())],
        ]);

        $closed++;
        error_log('[auto_close_tickets] Closed ticket: ' . $doc->id());
    }

    echo json_encode(['success' => true, 'closed' => $closed]);

} catch (\Throwable $e) {
    error_log('[auto_close_tickets] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
