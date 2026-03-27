<?php

/**
 * ghl_create_conversation.php — Webhook for GHL Custom Workflow Action.
 * 
 * This endpoint is triggered by GHL Workflows to automatically create/sync
 * a conversation in NOLA SMS Pro.
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require __DIR__ . '/firestore_client.php';
require __DIR__ . '/../auth_helpers.php';
require __DIR__ . '/../services/GhlClient.php';

// 1. Security Check
validate_api_request();

// 2. Parse Input
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: $_POST;

$contactId = $payload['contactId'] ?? $payload['contact_id'] ?? null;
$locationId = get_ghl_location_id() ?: ($payload['locationId'] ?? $payload['location_id'] ?? null);

if (!$contactId || !$locationId) {
    http_response_code(400);
    echo json_encode([
        'success' => false, 
        'error' => 'Missing required fields', 
        'received' => [
            'contactId' => $contactId,
            'locationId' => $locationId
        ]
    ]);
    exit;
}

try {
    $db = get_firestore();
    $client = new GhlClient($db, $locationId);

    // ── 1. Create/Get conversation on GHL ───────────────────────────────────
    $ghlPayload = json_encode([
        'locationId' => $locationId,
        'contactId'  => $contactId,
    ]);

    // Conversations endpoint uses API version 2021-04-15
    $resp = $client->request('POST', '/conversations/', $ghlPayload, '2021-04-15');
    $ghlData = json_decode($resp['body'], true);

    if ($resp['status'] >= 400) {
        // If it already exists, GHL might return a 400/409 with the ID or a message
        // However, we'll try to handle common errors here
        if ($resp['status'] === 400 && str_contains($resp['body'], 'already exists')) {
            // Logic to fetch existing might be needed, but usually GHL returns the existing one or we can fetch it
        }
        
        http_response_code($resp['status']);
        echo json_encode([
            'success'    => false,
            'error'      => 'GHL API error',
            'ghl_status' => $resp['status'],
            'ghl_error'  => $ghlData['message'] ?? $resp['body'],
        ]);
        exit;
    }

    $ghlConvId = $ghlData['conversation']['id'] ?? $ghlData['id'] ?? null;

    // ── 2. Fetch contact details to normalize phone number ─────────────────
    $contactResp = $client->request('GET', "/contacts/{$contactId}");
    $contactData = json_decode($contactResp['body'], true);
    $phone = $contactData['contact']['phone'] ?? '';
    $contactName = $contactData['contact']['name'] 
        ?? trim(($contactData['contact']['firstName'] ?? '') . ' ' . ($contactData['contact']['lastName'] ?? ''))
        ?? 'Contact';

    // Normalize phone to local format (09XXXXXXXXX)
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    }

    // ── 3. Sync to local Firestore ──────────────────────────────────────────
    $now = new \DateTimeImmutable();
    $localDocId = "{$locationId}_conv_{$digits}";

    $db->collection('conversations')->document($localDocId)->set([
        'id'                   => $localDocId,
        'location_id'          => $locationId,
        'type'                 => 'direct',
        'name'                 => $contactName,
        'ghl_conversation_id'  => $ghlConvId,
        'ghl_contact_id'       => $contactId,
        'members'              => [$digits],
        'last_message'         => null,
        'last_message_at'      => new \Google\Cloud\Core\Timestamp($now),
        'updated_at'           => new \Google\Cloud\Core\Timestamp($now),
        'source'               => 'ghl_workflow',
    ], ['merge' => true]);

    echo json_encode([
        'success' => true,
        'ghl_conversation_id' => $ghlConvId,
        'local_conversation_id' => $localDocId,
        'message' => 'Conversation synced successfully'
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
