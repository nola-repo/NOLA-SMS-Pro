<?php

/**
 * templates.php — SMS Templates CRUD endpoint.
 *
 * All operations scoped by X-GHL-Location-ID header.
 * Firestore collection: integrations/{locId}/templates
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

    $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string) $locId);

    // ── GET — list all templates for this location ──────────────────────
    if ($method === 'GET') {
        require_once __DIR__ . '/cache_helper.php';
        $cacheKey = "templates_list_{$locId}";
        $registryKey = "templates_registry_{$locId}";

        $cachedTemplates = NolaCache::get($cacheKey);
        if ($cachedTemplates !== null) {
            echo json_encode([
                'success' => true,
                'data'    => $cachedTemplates,
                'cached'  => true,
            ], JSON_PRETTY_PRINT);
            exit;
        }

        $query = $db->collection('integrations')->document($intDocId)->collection('templates')
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
                'category'   => $d['category'] ?? 'General',
                'created_at' => isset($d['created_at']) ? $d['created_at']->formatAsString() : null,
                'updated_at' => isset($d['updated_at']) ? $d['updated_at']->formatAsString() : null,
            ];
        }

        NolaCache::setWithRegistry($registryKey, $cacheKey, $rows, 600); // 10 minutes cache

        echo json_encode([
            'success' => true,
            'data'    => $rows,
            'cached'  => false,
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

        $category = trim($input['category'] ?? '');
        if (!$category) {
            $category = 'General';
        }
        $allowed = ['Appointments', 'Marketing', 'Transactional', 'General'];
        if (!in_array($category, $allowed, true)) {
            $category = 'General';
        }

        $now   = new \DateTimeImmutable();
        $docId = uniqid('tpl_', true);

        $db->collection('integrations')->document($intDocId)->collection('templates')->document($docId)->set([
            'id'          => $docId,
            'name'        => $name,
            'content'     => $content,
            'category'    => $category,
            'created_at'  => new \Google\Cloud\Core\Timestamp($now),
            'updated_at'  => new \Google\Cloud\Core\Timestamp($now),
        ]);

        // Invalidate templates cache
        try {
            require_once __DIR__ . '/cache_helper.php';
            NolaCache::deleteRegistry("templates_registry_{$locId}");
        } catch (\Throwable $cacheEx) {
            error_log("[templates] Cache invalidation failed: " . $cacheEx->getMessage());
        }

        echo json_encode([
            'success' => true,
            'data'    => [
                'id'         => $docId,
                'name'       => $name,
                'content'    => $content,
                'category'   => $category,
                'created_at' => $now->format(\DateTimeInterface::ATOM),
                'updated_at' => $now->format(\DateTimeInterface::ATOM),
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

        $category = isset($input['category']) ? trim((string)$input['category']) : null;

        if (!$id) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'id is required']);
            exit;
        }

        if (!$name && !$content && $category === null) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'name, content, or category is required']);
            exit;
        }

        // Verify document exists
        $docRef = $db->collection('integrations')->document($intDocId)->collection('templates')->document($id);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }

        $updateData = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        ];
        if ($name)    $updateData['name']    = $name;
        if ($content) $updateData['content'] = $content;
        if ($category !== null) {
            $allowed = ['Appointments', 'Marketing', 'Transactional', 'General'];
            if ($category === '' || !in_array($category, $allowed, true)) {
                $category = 'General';
            }
            $updateData['category'] = $category;
        }

        $docRef->set($updateData, ['merge' => true]);

        // Invalidate templates cache
        try {
            require_once __DIR__ . '/cache_helper.php';
            NolaCache::deleteRegistry("templates_registry_{$locId}");
        } catch (\Throwable $cacheEx) {
            error_log("[templates] Cache invalidation failed: " . $cacheEx->getMessage());
        }

        // Fetch updated document for full response row
        $updatedSnap = $docRef->snapshot();
        $updatedData = $updatedSnap->data();

        echo json_encode([
            'success' => true,
            'message' => 'Template updated',
            'data'    => [
                'id'         => $id,
                'name'       => $updatedData['name'] ?? '',
                'content'    => $updatedData['content'] ?? '',
                'category'   => $updatedData['category'] ?? 'General',
                'created_at' => isset($updatedData['created_at']) ? $updatedData['created_at']->formatAsString() : null,
                'updated_at' => isset($updatedData['updated_at']) ? $updatedData['updated_at']->formatAsString() : null,
            ]
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

        $docRef = $db->collection('integrations')->document($intDocId)->collection('templates')->document($id);
        $snap   = $docRef->snapshot();

        if (!$snap->exists()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Template not found']);
            exit;
        }

        $docRef->delete();

        // Invalidate templates cache
        try {
            require_once __DIR__ . '/cache_helper.php';
            NolaCache::deleteRegistry("templates_registry_{$locId}");
        } catch (\Throwable $cacheEx) {
            error_log("[templates] Cache invalidation failed: " . $cacheEx->getMessage());
        }

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
