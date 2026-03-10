<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';

$db = get_firestore();

/**
 * Get the latest GHL credentials from Firestore.
 */
function getGHLIntegration($db)
{
    // We try to get the 'ghl' document or any document starting with 'ghl_'
    $integrations = $db->collection('integrations')->limit(1)->documents();
    foreach ($integrations as $doc) {
        if ($doc->exists()) {
            return $doc->data();
        }
    }
    return null;
}

$integration = getGHLIntegration($db);
if (!$integration) {
    http_response_code(500);
    echo json_encode(['error' => 'GHL integration not configured in Firestore.']);
    exit;
}

$GHL_API_URL = 'https://services.leadconnectorhq.com';
$GHL_LOCATION_ID = $integration['location_id'] ?? null;
$GHL_API_TOKEN = $integration['access_token'] ?? null;

if (!$GHL_LOCATION_ID || !$GHL_API_TOKEN) {
    http_response_code(500);
    echo json_encode(['error' => 'GHL location_id or access_token missing in Firestore.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Transform a GHL contact to our simplified format.
 */
function transformGHLContact(array $contact): array
{
    $name = $contact['name']
        ?? trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? ''))
        ?: 'Unknown';

    return [
        'id' => $contact['id'] ?? null,
        'name' => $name,
        'phone' => $contact['phone'] ?? $contact['mobileNumber'] ?? '',
        'email' => $contact['email'] ?? '',
    ];
}

try {
    // ── GET — fetch contacts ────────────────────────────────────────────
    if ($method === 'GET') {
        $url = "{$GHL_API_URL}/contacts?locationId={$GHL_LOCATION_ID}&limit=100";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$GHL_API_TOKEN}",
                'Content-Type: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to fetch contacts from GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contacts = $data['contacts'] ?? $data['data'] ?? (is_array($data) ? $data : []);

        $transformed = array_values(array_filter(
            array_map('transformGHLContact', $contacts),
        fn($c) => !empty($c['phone'])
        ));

        echo json_encode($transformed, JSON_PRETTY_PRINT);
        exit;
    }

    // ── POST — create a contact ─────────────────────────────────────────
    if ($method === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $name = trim($input['name'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $email = trim($input['email'] ?? '');

        if (!$name || !$phone) {
            http_response_code(400);
            echo json_encode(['error' => 'Name and phone are required']);
            exit;
        }

        $nameParts = explode(' ', $name, 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        // Convert to E.164 (+63…)
        $digits = preg_replace('/\D/', '', $phone);
        if (str_starts_with($digits, '0')) {
            $digits = '63' . substr($digits, 1);
        }
        if (!str_starts_with($digits, '63')) {
            $digits = '63' . $digits;
        }
        $phoneE164 = '+' . $digits;

        $payload = json_encode(array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phoneE164,
            'email' => $email ?: null,
            'locationId' => $GHL_LOCATION_ID,
        ]));

        $url = "{$GHL_API_URL}/contacts?locationId={$GHL_LOCATION_ID}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$GHL_API_TOKEN}",
                'Content-Type: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to create contact in GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contact = $data['contact'] ?? $data;
        echo json_encode(transformGHLContact($contact), JSON_PRETTY_PRINT);
        exit;
    }

    // ── PUT — update a contact ──────────────────────────────────────────
    if ($method === 'PUT') {
        $input = json_decode(file_get_contents('php://input'), true) ?: [];
        $id = $input['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }

        $nameParts = explode(' ', trim($input['name'] ?? ''), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $phoneE164 = '';
        if (!empty($input['phone'])) {
            $digits = preg_replace('/\D/', '', $input['phone']);
            if (str_starts_with($digits, '0')) {
                $digits = '63' . substr($digits, 1);
            }
            if (!str_starts_with($digits, '63')) {
                $digits = '63' . $digits;
            }
            $phoneE164 = '+' . $digits;
        }

        $payload = json_encode(array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phoneE164 ?: null,
            'email' => $input['email'] ?? null,
        ]));

        $url = "{$GHL_API_URL}/contacts/{$id}?locationId={$GHL_LOCATION_ID}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$GHL_API_TOKEN}",
                'Content-Type: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to update contact in GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        $data = json_decode($body, true);
        $contact = $data['contact'] ?? $data;
        echo json_encode(transformGHLContact($contact), JSON_PRETTY_PRINT);
        exit;
    }

    // ── DELETE — delete a contact ───────────────────────────────────────
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'Contact ID is required']);
            exit;
        }

        $url = "{$GHL_API_URL}/contacts/{$id}?locationId={$GHL_LOCATION_ID}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$GHL_API_TOKEN}",
                'Content-Type: application/json',
                'Version: 2021-07-28',
            ],
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status >= 400) {
            http_response_code($status);
            echo json_encode([
                'error' => 'Failed to delete contact from GHL',
                'status' => $status,
                'details' => $body,
            ]);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $id], JSON_PRETTY_PRINT);
        exit;
    }

    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to process GHL contact request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
