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
require __DIR__ . '/../install_helpers.php';
require __DIR__ . '/../services/GhlClient.php';

function normalize_payload_section($value): array
{
    if (is_array($value)) {
        return $value;
    }
    if (is_string($value) && trim($value) !== '') {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function nested_payload_value(array $payload, array $path)
{
    $current = $payload;
    foreach ($path as $segment) {
        if (!is_array($current) || !array_key_exists($segment, $current)) {
            return null;
        }
        $current = $current[$segment];
    }
    return $current;
}

function first_non_empty_payload_value(array $payload, array $paths): ?string
{
    foreach ($paths as $path) {
        $value = is_array($path) ? nested_payload_value($payload, $path) : ($payload[$path] ?? null);
        if ($value !== null && !is_array($value) && !is_object($value) && trim((string)$value) !== '') {
            $value = trim((string)$value);
            if (strpos($value, '{{') === false) {
                return $value;
            }
        }
    }
    return null;
}

// 1. Security Check
validate_api_request();

$db = get_firestore();
$jwtCtx = auth_get_optional_jwt_context($db);

// 2. Parse Input
$raw = file_get_contents('php://input');
$payload = json_decode($raw, true) ?: $_POST;
$payload = is_array($payload) ? $payload : [];
$payload['customData'] = normalize_payload_section($payload['customData'] ?? []);
$payload['data'] = normalize_payload_section($payload['data'] ?? []);
$payload['contact'] = normalize_payload_section($payload['contact'] ?? ($payload['data']['contact'] ?? []));
$payload['location'] = normalize_payload_section($payload['location'] ?? ($payload['data']['location'] ?? []));
$payload['workflow'] = normalize_payload_section($payload['workflow'] ?? []);

$contactId = first_non_empty_payload_value($payload, [
    'contactId',
    'contact_id',
    ['customData', 'contactId'],
    ['customData', 'contact_id'],
    ['data', 'contactId'],
    ['data', 'contact_id'],
    ['contact', 'id'],
    ['contact', 'contactId'],
    ['workflow', 'contactId'],
]);
$locationId = get_ghl_location_id() ?: first_non_empty_payload_value($payload, [
    'locationId',
    'location_id',
    ['customData', 'locationId'],
    ['customData', 'location_id'],
    ['data', 'locationId'],
    ['data', 'location_id'],
    ['location', 'id'],
    ['location', 'locationId'],
    ['workflow', 'locationId'],
    ['workflow', 'location_id'],
]);

if (!$contactId || !$locationId) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields',
        'message' => 'Pass contactId and locationId either as flat fields, customData fields, contact.id, location.id, or X-GHL-Location-ID.',
        'received' => [
            'contactId' => $contactId,
            'locationId' => $locationId
        ],
        'event_details' => [
            'action' => 'Create conversation',
            'status' => 'failed',
            'reason' => 'missing_required_fields',
        ],
    ]);
    exit;
}

auth_assert_ghl_api_location_allowed($db, $jwtCtx, (string) $locationId);
$tokenRegistryId = auth_resolve_ghl_token_registry_id($db, $jwtCtx, (string) $locationId);

$installGate = install_location_sms_gate($db, (string) $locationId);
if (empty($installGate['allowed'])) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => (string) ($installGate['reason'] ?? 'NOLA SMS Pro is not installed for this sub-account.'),
        'code' => (string) ($installGate['code'] ?? 'install_blocked'),
    ]);
    exit;
}

try {
    $client = new GhlClient($db, $locationId, $tokenRegistryId);

    // ── 1. Create/Get conversation on GHL ───────────────────────────────────
    $ghlPayload = json_encode([
        'locationId' => $locationId,
        'contactId' => $contactId,
    ]);

    // Conversations endpoint uses API version 2021-04-15
    $resp = $client->request('POST', '/conversations/', $ghlPayload, '2021-04-15');
    $ghlData = json_decode($resp['body'], true);

    $ghlConvId = null;

    if ($resp['status'] >= 400) {
        // "Conversation already exists" is NOT an error — search for the existing one
        if ($resp['status'] === 400 && str_contains($resp['body'], 'already exists')) {
            $searchResp = $client->request(
                'GET',
                '/conversations/search?contactId=' . urlencode($contactId) . '&locationId=' . urlencode($locationId),
                null,
                '2021-04-15'
            );
            $searchData = json_decode($searchResp['body'], true);
            $ghlConvId = $searchData['conversations'][0]['id'] ?? null;

            if (!$ghlConvId) {
                // Search failed — report it but still continue with Firestore sync
                error_log("GHL conversation exists but search returned no results for contact {$contactId}");
            }
        }
        else {
            // Genuine API error
            http_response_code($resp['status']);
            echo json_encode([
                'success' => false,
                'error' => 'GHL API error',
                'ghl_status' => $resp['status'],
                'ghl_error' => $ghlData['message'] ?? $resp['body'],
            ]);
            exit;
        }
    }
    else {
        $ghlConvId = $ghlData['conversation']['id'] ?? $ghlData['id'] ?? null;
    }

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
        'id' => $localDocId,
        'location_id' => $locationId,
        'type' => 'direct',
        'name' => $contactName,
        'ghl_conversation_id' => $ghlConvId,
        'ghl_contact_id' => $contactId,
        'members' => [$digits],
        'last_message' => null,
        'last_message_at' => new \Google\Cloud\Core\Timestamp($now),
        'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        'source' => 'ghl_workflow',
    ], ['merge' => true]);

    echo json_encode([
        'success' => true,
        'ghl_conversation_id' => $ghlConvId,
        'local_conversation_id' => $localDocId,
        'message' => 'Conversation synced successfully',
        'event_details' => [
            'action' => 'Create conversation',
            'status' => 'executed',
            'location_id' => $locationId,
            'contact_id' => $contactId,
            'ghl_conversation_id' => $ghlConvId,
            'local_conversation_id' => $localDocId,
        ],
    ]);

}
catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
