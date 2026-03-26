<?php

/**
 * ghl-conversations.php — GHL Conversation endpoint.
 *
 * POST /api/ghl-conversations
 *   Creates a conversation on the GHL Dashboard and syncs it
 *   into the local Firestore conversations collection.
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require __DIR__ . '/services/GhlClient.php';

// Standardized Auth Check
validate_api_request();

$db     = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$locId  = get_ghl_location_id();

// ── Only POST is supported ──────────────────────────────────────────────
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Parse input ─────────────────────────────────────────────────────────
$input       = json_decode(file_get_contents('php://input'), true) ?: [];
$contactId   = $input['contactId'] ?? null;
$contactName = $input['contactName'] ?? null;

if (!$contactId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contactId is required']);
    exit;
}

if (!$locId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing location_id']);
    exit;
}

try {
    $client = new GhlClient($db, $locId);

    // ── 1. Create conversation on GHL ───────────────────────────────────
    $payload = json_encode([
        'locationId' => $locId,
        'contactId'  => $contactId,
    ]);

    // NOTE: Conversations endpoint uses API version 2021-04-15
    $resp    = $client->request('POST', '/conversations/', $payload, '2021-04-15');
    $ghlData = json_decode($resp['body'], true);

    if ($resp['status'] >= 400) {
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

    // ── 2. Fetch the contact's phone number for the local conversation ID ─
    $contactResp = $client->request('GET', "/contacts/{$contactId}");
    $contactData = json_decode($contactResp['body'], true);
    $phone       = $contactData['contact']['phone'] ?? '';

    // Normalize to 09XXXXXXXXX format for the conversation document ID
    $digits = preg_replace('/\D/', '', $phone);
    if (str_starts_with($digits, '63')) {
        $digits = '0' . substr($digits, 2);
    }

    // Use contactName from request, fallback to GHL contact name
    $displayName = $contactName
        ?: ($contactData['contact']['name']
            ?? trim(($contactData['contact']['firstName'] ?? '') . ' ' . ($contactData['contact']['lastName'] ?? ''))
            ?: 'Contact');

    // ── 3. Write to local Firestore conversations collection ────────────
    $now        = new \DateTimeImmutable();
    $localDocId = "{$locId}_conv_{$digits}";

    $db->collection('conversations')->document($localDocId)->set([
        'id'                   => $localDocId,
        'location_id'          => $locId,
        'type'                 => 'direct',
        'name'                 => $displayName,
        'ghl_conversation_id'  => $ghlConvId,
        'ghl_contact_id'       => $contactId,
        'members'              => [$digits],
        'last_message'         => null,
        'last_message_at'      => new \Google\Cloud\Core\Timestamp($now),
        'updated_at'           => new \Google\Cloud\Core\Timestamp($now),
        'source'               => 'ghl_sync',
    ], ['merge' => true]);

    // ── 4. Success response ─────────────────────────────────────────────
    echo json_encode([
        'success'               => true,
        'ghl_conversation_id'   => $ghlConvId,
        'local_conversation_id' => $localDocId,
        'message'               => 'Conversation created',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to create conversation',
        'message' => $e->getMessage(),
    ]);
}
