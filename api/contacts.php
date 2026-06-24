<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/services/ApiValueFormatter.php';
require_once __DIR__ . '/services/PhoneNormalizer.php';

$db = get_firestore();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function contacts_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
}

function contacts_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_PRETTY_PRINT);
    exit;
}

function contacts_normalized_phone(?string $phone): ?string
{
    $phone = trim((string)$phone);
    if ($phone === '') {
        return null;
    }

    return PhoneNormalizer::philippineMobile($phone) ?: preg_replace('/\D+/', '', $phone);
}

function contacts_row($doc): array
{
    $d = $doc->data();
    return [
        'id' => $doc->id(),
        'name' => $d['name'] ?? null,
        'phone' => $d['phone'] ?? null,
        'phone_normalized' => $d['phone_normalized'] ?? null,
        'email' => $d['email'] ?? null,
        'ghl_contact_id' => $d['ghl_contact_id'] ?? null,
        'location_id' => $d['location_id'] ?? null,
        'created_at' => ApiValueFormatter::timestamp($d['created_at'] ?? null),
        'updated_at' => ApiValueFormatter::timestamp($d['updated_at'] ?? null),
    ];
}

function contacts_find_duplicate($db, string $locId, string $normalizedPhone, ?string $excludeId = null): ?array
{
    if ($normalizedPhone === '') {
        return null;
    }

    $queries = [
        $db->collection('contacts')
            ->where('location_id', '==', $locId)
            ->where('phone_normalized', '==', $normalizedPhone)
            ->limit(2)
            ->documents(),
        $db->collection('contacts')
            ->where('location_id', '==', $locId)
            ->where('phone', '==', $normalizedPhone)
            ->limit(2)
            ->documents(),
    ];

    foreach ($queries as $docs) {
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            if ($excludeId !== null && $doc->id() === $excludeId) {
                continue;
            }
            return contacts_row($doc);
        }
    }

    return null;
}

function contacts_invalidate_cache(string $locId): void
{
    try {
        NolaCache::deleteRegistry("contacts_registry_{$locId}");
        NolaCache::deleteRegistry("ghl_contacts_registry_{$locId}");
    } catch (\Throwable $cacheEx) {
        error_log("[contacts] Cache invalidation failed: " . $cacheEx->getMessage());
    }
}

try {
    $locId = get_ghl_location_id();
    if (!$locId) {
        contacts_json_response(['success' => false, 'error' => 'Missing location_id'], 400);
    }

    auth_require_api_or_jwt_for_location($db, (string)$locId);

    if ($method === 'GET') {
        $limit = min((int)($_GET['limit'] ?? 50), 100);
        $offset = max((int)($_GET['offset'] ?? 0), 0);
        $id = trim((string)($_GET['id'] ?? ''));
        $phone = trim((string)($_GET['phone'] ?? ''));
        $search = trim((string)($_GET['search'] ?? $_GET['q'] ?? ''));

        if ($id !== '') {
            $snap = $db->collection('contacts')->document($id)->snapshot();
            if (!$snap->exists()) {
                contacts_json_response(['success' => false, 'error' => 'Contact not found'], 404);
            }
            $row = contacts_row($snap);
            if ((string)($row['location_id'] ?? '') !== (string)$locId) {
                contacts_json_response(['success' => false, 'error' => 'Permission denied'], 403);
            }
            contacts_json_response(['success' => true, 'data' => $row]);
        }

        $cacheTtl = 300;
        $paramsHash = md5(serialize([$limit, $offset, $phone, $search]));
        $cacheKey = "contacts_list_{$locId}_{$paramsHash}";
        $registryKey = "contacts_registry_{$locId}";
        $bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);

        if (!$bypassCache) {
            $cachedPayload = NolaCache::get($cacheKey);
            if (is_array($cachedPayload)) {
                NolaCache::sendApiCacheHeaders($cacheTtl, true);
                echo json_encode(NolaCache::withCacheMeta($cachedPayload, $cacheTtl, true, 'location'), JSON_PRETTY_PRINT);
                exit;
            }
        }

        $queryLimit = $search !== '' ? 500 : $limit;
        $q = $db->collection('contacts')
            ->where('location_id', '==', $locId)
            ->orderBy('created_at', 'DESC');

        if ($phone !== '') {
            $normalized = contacts_normalized_phone($phone);
            if ($normalized !== null) {
                $q = $q->where('phone_normalized', '==', $normalized);
            } else {
                $q = $q->where('phone', '==', $phone);
            }
        }

        $docs = $q->limit($queryLimit)->offset($search !== '' ? 0 : $offset)->documents();
        $rows = [];
        $needle = strtolower($search);
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }

            $row = contacts_row($doc);
            if ($search !== '') {
                $haystack = strtolower(implode(' ', array_filter([
                    $row['name'] ?? '',
                    $row['phone'] ?? '',
                    $row['phone_normalized'] ?? '',
                    $row['email'] ?? '',
                    $row['ghl_contact_id'] ?? '',
                ])));
                if (!str_contains($haystack, $needle)) {
                    continue;
                }
            }
            $rows[] = $row;
        }

        $total = count($rows);
        if ($search !== '') {
            $rows = array_slice($rows, $offset, $limit);
        }

        $responsePayload = [
            'success' => true,
            'data' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];

        NolaCache::setWithRegistry($registryKey, $cacheKey, $responsePayload, $cacheTtl);
        NolaCache::sendApiCacheHeaders($cacheTtl, $bypassCache ? 'BYPASS' : false);
        echo json_encode(NolaCache::withCacheMeta($responsePayload, $cacheTtl, $bypassCache ? 'BYPASS' : false, 'location'), JSON_PRETTY_PRINT);
        exit;
    }

    if ($method === 'POST') {
        $body = contacts_json_body();
        $name = trim((string)($body['name'] ?? ''));
        $phone = trim((string)($body['phone'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $normalized = contacts_normalized_phone($phone);

        if ($phone === '') {
            contacts_json_response(['success' => false, 'error' => 'Phone is required'], 400);
        }
        if ($normalized === null || $normalized === '') {
            contacts_json_response(['success' => false, 'error' => 'Valid phone is required'], 400);
        }

        $duplicate = contacts_find_duplicate($db, (string)$locId, $normalized);
        if ($duplicate !== null) {
            contacts_json_response([
                'success' => false,
                'error' => 'A contact with this phone already exists for this location',
                'code' => 'duplicate_phone',
                'data' => $duplicate,
            ], 409);
        }

        $now = new DateTimeImmutable();
        $data = [
            'name' => $name ?: null,
            'phone' => $phone,
            'phone_normalized' => $normalized,
            'email' => $email ?: null,
            'ghl_contact_id' => $body['ghl_contact_id'] ?? null,
            'location_id' => $locId,
            'created_at' => new \Google\Cloud\Core\Timestamp($now),
            'updated_at' => new \Google\Cloud\Core\Timestamp($now),
        ];

        $docRef = $db->collection('contacts')->add($data);
        contacts_invalidate_cache((string)$locId);

        contacts_json_response([
            'success' => true,
            'id' => $docRef->id(),
            'data' => array_merge(['id' => $docRef->id()], $data, [
                'created_at' => $now->format(DateTimeInterface::ATOM),
                'updated_at' => $now->format(DateTimeInterface::ATOM),
            ]),
        ], 201);
    }

    if ($method === 'PUT' || $method === 'PATCH') {
        $body = contacts_json_body();
        $id = trim((string)($body['id'] ?? $_GET['id'] ?? ''));
        if ($id === '') {
            contacts_json_response(['success' => false, 'error' => 'id is required'], 400);
        }

        $docRef = $db->collection('contacts')->document($id);
        $snap = $docRef->snapshot();
        if (!$snap->exists()) {
            contacts_json_response(['success' => false, 'error' => 'Contact not found'], 404);
        }
        $existing = $snap->data();
        if ((string)($existing['location_id'] ?? '') !== (string)$locId) {
            contacts_json_response(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $updates = [
            'updated_at' => new \Google\Cloud\Core\Timestamp(new DateTimeImmutable()),
        ];

        if (array_key_exists('name', $body)) {
            $updates['name'] = trim((string)$body['name']) ?: null;
        }
        if (array_key_exists('email', $body)) {
            $updates['email'] = trim((string)$body['email']) ?: null;
        }
        if (array_key_exists('ghl_contact_id', $body)) {
            $updates['ghl_contact_id'] = $body['ghl_contact_id'] ?: null;
        }
        if (array_key_exists('phone', $body)) {
            $phone = trim((string)$body['phone']);
            $normalized = contacts_normalized_phone($phone);
            if ($phone === '' || $normalized === null || $normalized === '') {
                contacts_json_response(['success' => false, 'error' => 'Valid phone is required'], 400);
            }
            $duplicate = contacts_find_duplicate($db, (string)$locId, $normalized, $id);
            if ($duplicate !== null) {
                contacts_json_response([
                    'success' => false,
                    'error' => 'A contact with this phone already exists for this location',
                    'code' => 'duplicate_phone',
                    'data' => $duplicate,
                ], 409);
            }
            $updates['phone'] = $phone;
            $updates['phone_normalized'] = $normalized;
        }

        if (count($updates) === 1) {
            contacts_json_response(['success' => false, 'error' => 'No contact fields provided'], 400);
        }

        $docRef->set($updates, ['merge' => true]);
        contacts_invalidate_cache((string)$locId);

        contacts_json_response([
            'success' => true,
            'message' => 'Contact updated',
            'data' => contacts_row($docRef->snapshot()),
        ]);
    }

    if ($method === 'DELETE') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '') {
            $body = contacts_json_body();
            $id = trim((string)($body['id'] ?? ''));
        }
        if ($id === '') {
            contacts_json_response(['success' => false, 'error' => 'id is required'], 400);
        }

        $docRef = $db->collection('contacts')->document($id);
        $snap = $docRef->snapshot();
        if (!$snap->exists()) {
            contacts_json_response(['success' => false, 'error' => 'Contact not found'], 404);
        }
        if ((string)($snap->data()['location_id'] ?? '') !== (string)$locId) {
            contacts_json_response(['success' => false, 'error' => 'Permission denied'], 403);
        }

        $docRef->delete();
        contacts_invalidate_cache((string)$locId);
        contacts_json_response(['success' => true, 'message' => 'Contact deleted']);
    }

    contacts_json_response(['success' => false, 'error' => 'Method not allowed'], 405);
} catch (\Throwable $e) {
    error_log('[contacts] Failed to handle contacts request: ' . $e->getMessage());
    contacts_json_response([
        'success' => false,
        'error' => 'Failed to handle contacts request',
        'message' => $e->getMessage(),
    ], 500);
}

