<?php

/**
 * templates.php — SMS Templates CRUD endpoint.
 *
 * All operations scoped by X-GHL-Location-ID header.
 * Firestore collection: templates
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

    // ── GET — list all templates for this location ──────────────────────
    if ($method === 'GET') {
        $query = $db->collection('templates')
            ->where('location_id', '==', $locId)
            ->orderBy('updated_at', 'DESC')
            ->documents();

        $rows = [];
        foreach ($query as $doc) {
            if (!$doc->exists()) continue;
            $d = $doc->data();
            $rows[] = [
                'id'         => $doc->id(),
                'name'       => $d['name'] ?? '',
                'content'    => $d['content'] ?? '',
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'updated_at' => isset($d['updated_at']) ? $d['updated_at']->formatAsString() : null,
            ];
        }

        echo json_encode([
            'success' => true,
            'data'    => $rows,
        ], JSON_PRETTY_PRINT);
        exit;
    }

    // ── POST — create a new template ────────────────────────────────────
    if ($method === 'POST') {
        $input   = json_decode(file_get_contents('php://input'), true) ?: [];
        $name    = trim($input['name'] ?? '');
        $content = trim($input['content'] ?? '');

        if (!$name || !$content) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'name and content are required']);
            exit;
        }

        $now   = new \DateTimeImmutable();
        $docId = uniqid('tpl_', true);

        $db->collection('templates')->document($docId)->set([
            'id'          => $docId,
            'location_id' => $locId,
            'name'        => $name,
            'content'     => $content,
            'created_at'  => new \Google\Cloud\Core\Timestamp($now),
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now),
        ]);

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'      => $docId,
                'name'    => $name,
                'content' => $content,
            ],
            'message' => 'Template created',
        ]);
        exit;
    }

    // ── PUT — update an existing template ───────────────────────────────
    if ($method === 'PUT') {
        $input   = json_decode(file_get_contents('php://input'), true) ?: [];
        $id      = trim($input['id'] ?? '');
        $name    = trim($input['name'] ?? '');
        $content = trim($input['content'] ?? '');

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id is required']);
            exit;
        }

        if (!$name && !$content) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'name or content is required']);
            exit;
        }

        // Verify document exists and belongs to this location
        $docRef = $db->collection('templates')->document($id);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }

        if (($snap->data()['location_id'] ?? '') !== $locId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $updateData = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        ];
        if ($name)    $updateData['name']    = $name;
        if ($content) $updateData['content'] = $content;

        $docRef->set($updateData, ['merge' => true]);

        echo json_encode([
            'success' => true,
            'message' => 'Template updated',
        ]);
        exit;
    }

    // ── DELETE — delete a template ──────────────────────────────────────
    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Missing id parameter']);
            exit;
        }

        $docRef = $db->collection('templates')->document($id);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }

        if (($snap->data()['location_id'] ?? '') !== $locId) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Permission denied']);
            exit;
        }

        $docRef->delete();

        echo json_encode([
            'success' => true,
            'message' => 'Template deleted',
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
        'error'   => 'Failed to process templates request',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
