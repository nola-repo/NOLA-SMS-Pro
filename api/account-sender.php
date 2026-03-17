<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

// 1. Authentication
validate_api_request();

// 2. Get Location ID
$locId = get_ghl_location_id();

if (!$locId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$docId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);

try {
    if ($method === 'GET') {
        // Fetch configuration
        $docRef = $db->collection('integrations')->document($docId);
        $snapshot = $docRef->snapshot();

        $data = $snapshot->exists() ? $snapshot->data() : [];

        echo json_encode([
            'status' => 'success',
            'data' => [
                'approved_sender_id' => $data['approved_sender_id'] ?? null,
                'semaphore_api_key' => $data['semaphore_api_key'] ?? null,
                'free_usage_count' => $data['free_usage_count'] ?? 0,
                'system_default_sender' => 'NOLASMSPro' // As requested
            ]
        ]);
        exit;
    }

    if ($method === 'POST') {
        // Update semaphore_api_key
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) $payload = $_POST;

        $apiKey = $payload['semaphore_api_key'] ?? null;

        if ($apiKey === null) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Missing semaphore_api_key']);
            exit;
        }

        $db->collection('integrations')->document($docId)->set([
            'semaphore_api_key' => $apiKey,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['status' => 'success', 'message' => 'Account sender configuration updated']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal Server Error', 'details' => $e->getMessage()]);
}
