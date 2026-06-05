<?php

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
$locId  = get_ghl_location_id();

// ── Helpers ──────────────────────────────────────────────────────────────────

function ts_to_iso($ts): ?string
{
    if (!$ts) return null;
    if ($ts instanceof \Google\Cloud\Core\Timestamp) return $ts->get()->format('c');
    return null;
}

function now_ts(): \Google\Cloud\Core\Timestamp
{
    return new \Google\Cloud\Core\Timestamp(new \DateTime());
}

function send_json(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_PRETTY_PRINT);
    exit;
}

/**
 * Fire-and-forget notification to a Slack webhook.
 * Set SUPPORT_SLACK_WEBHOOK env var to enable.
 */
function notify_slack(string $text): void
{
    $webhookUrl = getenv('SUPPORT_SLACK_WEBHOOK');
    if (!$webhookUrl) return;
    try {
        $ch = curl_init($webhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['text' => $text]));
        curl_exec($ch);
        curl_close($ch);
    } catch (\Throwable $e) {
        error_log('[tickets] Slack notify failed: ' . $e->getMessage());
    }
}

/**
 * Send a basic email notification using PHP mail().
 * For production consider SendGrid / Mailgun env vars.
 */
function notify_email(string $to, string $subject, string $body): void
{
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;
    $from = getenv('SUPPORT_EMAIL_FROM') ?: 'support@nolasmspro.com';
    $headers = "From: NOLA SMS Pro <{$from}>\r\nContent-Type: text/html; charset=UTF-8";
    try {
        mail($to, $subject, nl2br(htmlspecialchars($body)), $headers);
    } catch (\Throwable $e) {
        error_log('[tickets] Email notify failed: ' . $e->getMessage());
    }
}

// ── Valid values ──────────────────────────────────────────────────────────────
$VALID_STATUSES   = ['open', 'in_progress', 'waiting', 'resolved', 'closed'];
$VALID_PRIORITIES = ['low', 'medium', 'high', 'urgent'];

// ─────────────────────────────────────────────────────────────────────────────
// GET — List Tickets
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    if (!$locId) {
        send_json(400, ['success' => false, 'error' => 'Missing location_id']);
    }

    $statusFilter = $_GET['status'] ?? null;
    $page  = max(1, (int)($_GET['page']  ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
    $offset = ($page - 1) * $limit;

    try {
        $q = $db->collection('support_tickets')
            ->where('location_id', '==', $locId);

        if ($statusFilter && in_array($statusFilter, $VALID_STATUSES)) {
            $q = $q->where('status', '==', $statusFilter);
        }

        $q = $q->orderBy('created_at', 'DESC')
               ->limit($limit)
               ->offset($offset);

        $tickets = [];
        foreach ($q->documents() as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $tickets[] = [
                'ticket_id'          => $doc->id(),
                'location_id'        => $d['location_id']        ?? null,
                'subject'            => $d['subject']            ?? '',
                'description'        => $d['description']        ?? '',
                'status'             => $d['status']             ?? 'open',
                'priority'           => $d['priority']           ?? 'medium',
                'contact_name'       => $d['contact_name']       ?? '',
                'contact_email'      => $d['contact_email']      ?? '',
                'contact_phone'      => $d['contact_phone']      ?? null,
                'assigned_agent'     => $d['assigned_agent']     ?? null,
                'assigned_agent_id'  => $d['assigned_agent_id']  ?? null,
                'created_at'         => ts_to_iso($d['created_at'] ?? null),
                'updated_at'         => ts_to_iso($d['updated_at'] ?? null),
            ];
        }

        send_json(200, [
            'success' => true,
            'data'    => $tickets,
            'total'   => count($tickets),
            'page'    => $page,
            'limit'   => $limit,
        ]);

    } catch (\Throwable $e) {
        error_log('[tickets] GET error: ' . $e->getMessage());
        send_json(500, ['success' => false, 'error' => 'Failed to fetch tickets', 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// POST — Create Ticket
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'POST') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = $_POST;

    // Allow location_id from body OR header
    $ticketLocId = $payload['location_id'] ?? $locId;
    if (!$ticketLocId) {
        send_json(400, ['success' => false, 'error' => 'Missing location_id']);
    }

    $subject      = trim($payload['subject']       ?? '');
    $description  = trim($payload['description']   ?? '');
    $priority     = $payload['priority']            ?? 'medium';
    $contactName  = trim($payload['contact_name']  ?? '');
    $contactEmail = trim($payload['contact_email'] ?? '');
    $contactPhone = trim($payload['contact_phone'] ?? '');

    if (!$subject || !$description || !$contactName || !$contactEmail) {
        send_json(400, ['success' => false, 'error' => 'Missing required fields: subject, description, contact_name, contact_email']);
    }

    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
        send_json(400, ['success' => false, 'error' => 'Invalid contact_email address']);
    }

    if (!in_array($priority, $VALID_PRIORITIES)) {
        $priority = 'medium';
    }

    $ticketId  = 'tkt_' . bin2hex(random_bytes(6));
    $now       = now_ts();

    $ticketData = [
        'ticket_id'     => $ticketId,
        'location_id'   => $ticketLocId,
        'subject'       => $subject,
        'description'   => $description,
        'status'        => 'open',
        'priority'      => $priority,
        'contact_name'  => $contactName,
        'contact_email' => $contactEmail,
        'contact_phone' => $contactPhone ?: null,
        'assigned_agent'    => null,
        'assigned_agent_id' => null,
        'created_at'    => $now,
        'updated_at'    => $now,
    ];

    try {
        $db->collection('support_tickets')->document($ticketId)->set($ticketData);

        // ── Slack Notification ────────────────────────────────────────────
        notify_slack(
            "🎫 *New Support Ticket* [{$priority}]\n" .
            "*ID:* {$ticketId}\n" .
            "*Subject:* {$subject}\n" .
            "*From:* {$contactName} ({$contactEmail})\n" .
            "*Location:* {$ticketLocId}\n" .
            "*Description:* " . mb_substr($description, 0, 200) . (mb_strlen($description) > 200 ? '...' : '')
        );

        send_json(201, [
            'success'   => true,
            'ticket_id' => $ticketId,
            'status'    => 'open',
            'message'   => 'Ticket created successfully',
        ]);

    } catch (\Throwable $e) {
        error_log('[tickets] POST error: ' . $e->getMessage());
        send_json(500, ['success' => false, 'error' => 'Failed to create ticket', 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PUT — Update Ticket (status, assignment, notes)
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'PUT') {
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) $payload = [];

    $ticketId = $payload['ticket_id'] ?? $_GET['ticket_id'] ?? null;
    if (!$ticketId) {
        send_json(400, ['success' => false, 'error' => 'Missing ticket_id']);
    }

    try {
        $docRef  = $db->collection('support_tickets')->document((string)$ticketId);
        $snap    = $docRef->snapshot();

        if (!$snap->exists()) {
            send_json(404, ['success' => false, 'error' => 'Ticket not found']);
        }

        $existing   = $snap->data();
        $existingLocId = $existing['location_id'] ?? null;

        // Scope check — users can only update their own tickets
        // Admins send requests without a specific locId header (or with X-Admin: true)
        $isAdminUpdate = !$locId || ($_SERVER['HTTP_X_ADMIN'] ?? '') === 'true';
        if (!$isAdminUpdate && $existingLocId !== $locId) {
            send_json(403, ['success' => false, 'error' => 'Permission denied']);
        }

        $updates = [
            ['path' => 'updated_at', 'value' => now_ts()],
        ];

        $newStatus = null;
        if (isset($payload['status']) && in_array($payload['status'], $VALID_STATUSES)) {
            $newStatus = $payload['status'];
            $updates[] = ['path' => 'status', 'value' => $newStatus];
        }

        if (isset($payload['assigned_agent'])) {
            $updates[] = ['path' => 'assigned_agent', 'value' => $payload['assigned_agent']];
        }
        if (isset($payload['assigned_agent_id'])) {
            $updates[] = ['path' => 'assigned_agent_id', 'value' => $payload['assigned_agent_id']];
        }
        if (isset($payload['note'])) {
            // Append note to a subcollection for audit trail
            $db->collection('support_tickets')
               ->document((string)$ticketId)
               ->collection('notes')
               ->newDocument()
               ->set([
                   'note'       => $payload['note'],
                   'created_by' => $payload['assigned_agent'] ?? 'System',
                   'created_at' => now_ts(),
               ]);
        }

        $docRef->update($updates);

        // ── Notify on resolution ──────────────────────────────────────────
        if ($newStatus === 'resolved') {
            $contactEmail = $existing['contact_email'] ?? null;
            $contactName  = $existing['contact_name']  ?? 'Customer';
            $subject      = $existing['subject']        ?? 'Your ticket';

            notify_email(
                $contactEmail,
                "Your Support Ticket Has Been Resolved — #{$ticketId}",
                "Hi {$contactName},\n\nYour support ticket \"{$subject}\" (#{$ticketId}) has been marked as resolved.\n\nIf you have further questions or the issue persists, please submit a new ticket.\n\nThank you,\nNOLA SMS Pro Support"
            );

            notify_slack(
                "✅ *Ticket Resolved* #{$ticketId}\n*Subject:* {$subject}\n*Customer:* {$contactName} ({$contactEmail})"
            );

            // Schedule auto-close: store resolved_at timestamp for the cron to pick up
            $docRef->update([
                ['path' => 'resolved_at', 'value' => now_ts()]
            ]);
        }

        // ── Notify agent when newly assigned ─────────────────────────────
        if (isset($payload['assigned_agent_id']) && !empty($payload['assigned_agent'])) {
            notify_slack(
                "📋 *Ticket Assigned* #{$ticketId}\n*To:* {$payload['assigned_agent']}\n*Subject:* " . ($existing['subject'] ?? '(unknown)')
            );
        }

        send_json(200, ['success' => true, 'message' => 'Ticket updated successfully']);

    } catch (\Throwable $e) {
        error_log('[tickets] PUT error: ' . $e->getMessage());
        send_json(500, ['success' => false, 'error' => 'Failed to update ticket', 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// DELETE — Admin: hard-delete a ticket
// ─────────────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    $raw      = file_get_contents('php://input');
    $payload  = json_decode($raw, true);
    if (!is_array($payload)) $payload = $_GET;

    $ticketId = $payload['ticket_id'] ?? $_GET['ticket_id'] ?? null;
    if (!$ticketId) {
        send_json(400, ['success' => false, 'error' => 'Missing ticket_id']);
    }

    try {
        $docRef = $db->collection('support_tickets')->document((string)$ticketId);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            send_json(404, ['success' => false, 'error' => 'Ticket not found']);
        }

        // Scope check
        $existingLocId = $snap->data()['location_id'] ?? null;
        if ($locId && $existingLocId !== $locId) {
            send_json(403, ['success' => false, 'error' => 'Permission denied']);
        }

        $docRef->delete();
        send_json(200, ['success' => true, 'message' => 'Ticket deleted']);

    } catch (\Throwable $e) {
        error_log('[tickets] DELETE error: ' . $e->getMessage());
        send_json(500, ['success' => false, 'error' => 'Failed to delete ticket', 'message' => $e->getMessage()]);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
send_json(405, ['success' => false, 'error' => 'Method Not Allowed']);
