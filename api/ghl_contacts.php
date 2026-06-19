<?php

/**
 * GET /api/ghl-contacts (routed via .htaccess to this file)
 *
 * Retrieves GHL contacts for a given location using its stored OAuth token.
 * All token lookup, caching, proactive refresh, and 401-retry logic is
 * handled exclusively by GhlClient — eliminating the race condition that
 * previously occurred when the legacy procedural code and GhlClient both
 * tried to refresh the same OAuth token simultaneously.
 *
 * Query Parameters:
 *   locationId  (required) — GHL location ID (also accepts location_id)
 *   X-GHL-Location-ID header — alternative to query param
 *
 * Responses:
 *   200  { contacts: [...] }
 *   400  { error: "Missing locationId" }
 *   401  When refresh fails with non-transient auth after a GHL 401 — GhlClient may return
 *        { "error": "Token refresh failed", "requires_reconnect": true }; transient cases use 503.
 * JWT: Authorization: Bearer + profile `active_location_id` / `company_id` and `ghl_token_ref`
 * constrain which location may be queried and where OAuth docs are loaded.
 *   404  { error: "..." } (e.g. integration not found for location)
 *   405  { error: "Method not allowed" }
 *   500  { error: "..." }
 */

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';
require __DIR__ . '/services/GhlClient.php';

// ── Standardized Auth Check ────────────────────────────────────────────────
validate_api_request();

$db = get_firestore();
$jwtCtx = auth_get_optional_jwt_context($db);

// ── 1. Read & validate locationId ─────────────────────────────────────────
// Accept from header (preferred for multi-tenant) or query param
$locationId = get_ghl_location_id()
    ?? $_GET['locationId']
    ?? $_GET['location_id']
    ?? null;

if (empty($locationId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing locationId — pass X-GHL-Location-ID header or ?locationId=']);
    exit;
}

auth_assert_ghl_api_location_allowed($db, $jwtCtx, (string) $locationId);
$tokenRegistryId = auth_resolve_ghl_token_registry_id($db, $jwtCtx, (string) $locationId);

// ── 2. Initialize GHL Client (Handles Token Lookup & Refresh) ──────────────
try {
    $ghlClient = new GhlClient($db, (string) $locationId, $tokenRegistryId);
} catch (\Exception $e) {
    error_log("[ghl_contacts] Client initialization failed: " . $e->getMessage());
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ── 3. Handle CRUD Requests ───────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function nola_normalize_contact_phone(?string $phone): string
{
    $digits = preg_replace('/\D/', '', (string)($phone ?? ''));
    if (preg_match('/^09\d{9}$/', $digits)) {
        return $digits;
    }
    if (preg_match('/^9\d{9}$/', $digits)) {
        return '0' . $digits;
    }
    if (preg_match('/^639\d{9}$/', $digits)) {
        return '0' . substr($digits, 2);
    }
    return $digits;
}

function nola_sync_contact_conversation($db, string $locationId, string $contactId, string $displayName, string $newPhone, ?string $oldPhone): void
{
    $newNormalized = nola_normalize_contact_phone($newPhone);
    $oldNormalized = nola_normalize_contact_phone($oldPhone);
    if ($newNormalized === '' && $oldNormalized === '') {
        return;
    }

    $newDocId = $newNormalized !== '' ? "{$locationId}_conv_{$newNormalized}" : null;
    $oldDocId = $oldNormalized !== '' ? "{$locationId}_conv_{$oldNormalized}" : null;
    $now = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable());
    $seenDocIds = [];

    $syncDoc = function ($docRef, array $existingData = []) use ($db, $locationId, $contactId, $displayName, $newNormalized, $newDocId, $now, &$seenDocIds) {
        if ($newDocId === null) {
            return;
        }

        $sourceDocId = $docRef->id();
        if (isset($seenDocIds[$sourceDocId])) {
            return;
        }
        $seenDocIds[$sourceDocId] = true;

        $payload = array_merge($existingData, [
            'id' => $newDocId,
            'location_id' => $locationId,
            'type' => 'direct',
            'name' => $displayName,
            'conversation_name' => $displayName,
            'ghl_contact_id' => $contactId,
            'members' => [$newNormalized],
            'updated_at' => $now,
        ]);

        if ($sourceDocId !== $newDocId) {
            $db->collection('conversations')->document($newDocId)->set($payload, ['merge' => true]);
            $docRef->delete();

            foreach (['messages', 'sms_logs'] as $collectionName) {
                $rows = $db->collection($collectionName)
                    ->where('conversation_id', '==', $sourceDocId)
                    ->documents();
                foreach ($rows as $row) {
                    if ($row->exists()) {
                        $row->reference()->set([
                            'conversation_id' => $newDocId,
                            'conversation_name' => $displayName,
                            'updated_at' => $now,
                        ], ['merge' => true]);
                    }
                }
            }
        } else {
            $docRef->set($payload, ['merge' => true]);
        }
    };

    if ($contactId !== '') {
        $conversationDocs = $db->collection('conversations')
            ->where('ghl_contact_id', '==', $contactId)
            ->documents();
        foreach ($conversationDocs as $doc) {
            if ($doc->exists()) {
                $data = $doc->data();
                if (($data['location_id'] ?? '') === $locationId) {
                    $syncDoc($doc->reference(), $data);
                }
            }
        }
    }

    if ($oldDocId !== null) {
        $oldSnap = $db->collection('conversations')->document($oldDocId)->snapshot();
        if ($oldSnap->exists()) {
            $syncDoc($oldSnap->reference(), $oldSnap->data());
        }
    }

    if ($newDocId !== null) {
        $newSnap = $db->collection('conversations')->document($newDocId)->snapshot();
        if ($newSnap->exists()) {
            $syncDoc($newSnap->reference(), $newSnap->data());
        }
    }

    try {
        require_once __DIR__ . '/cache_helper.php';
        NolaCache::deleteRegistry("conversations_registry_{$locationId}");
    } catch (\Throwable $cacheEx) {
        error_log("[ghl_contacts] Conversation cache invalidation failed: " . $cacheEx->getMessage());
    }
}

// ── GET: fetch contacts (with pagination) ─────────────────────────────────
if ($method === 'GET') {
    require_once __DIR__ . '/cache_helper.php';
    $cacheKey = "ghl_contacts_list_{$locationId}";
    $lastGoodCacheKey = "ghl_contacts_last_good_{$locationId}";
    $registryKey = "ghl_contacts_registry_{$locationId}";

    $cachedContacts = NolaCache::get($cacheKey);
    if ($cachedContacts !== null) {
        echo json_encode(['contacts' => $cachedContacts, 'cached' => true]);
        exit;
    }

    $allContacts = [];
    $path        = '/contacts/?locationId=' . urlencode($locationId) . '&limit=100';
    $pageCount   = 0;
    $maxPages    = 20; // Safety cap: sync up to 2,000 contacts at once

    do {
        $resp = $ghlClient->request('GET', $path);

        if ($resp['status'] >= 400) {
            if (!empty($allContacts)) break; // Return what we have so far

            $errorBody = json_decode((string)$resp['body'], true);
            $isReconnectRequired = is_array($errorBody) && !empty($errorBody['requires_reconnect']);
            $isTemporaryFailure = !$isReconnectRequired && (
                (int)$resp['status'] >= 500 ||
                (int)$resp['status'] === 429
            );

            if ($isTemporaryFailure) {
                $lastGoodContacts = NolaCache::get($lastGoodCacheKey);
                if (is_array($lastGoodContacts)) {
                    error_log("[ghl_contacts] Returning last-good contacts after temporary GHL failure for {$locationId} status={$resp['status']}");
                    http_response_code(200);
                    echo json_encode([
                        'contacts' => $lastGoodContacts,
                        'cached' => true,
                        'stale' => true,
                        'warning' => 'GHL contacts are temporarily unavailable; showing last synced contacts.',
                    ]);
                    exit;
                }
            }

            http_response_code($resp['status']);
            echo $resp['body'];
            exit;
        }

        $data         = json_decode($resp['body'], true);
        $pageContacts = $data['contacts'] ?? $data['data'] ?? [];
        if (is_array($pageContacts)) {
            $allContacts = array_merge($allContacts, $pageContacts);
        }

        // Follow GHL's nextPageUrl for pagination
        $meta        = $data['meta'] ?? [];
        $nextPageUrl = $meta['nextPageUrl'] ?? null;

        if ($nextPageUrl) {
            $parsed = parse_url($nextPageUrl);
            $path   = ($parsed['path'] ?? '/contacts/') . '?' . ($parsed['query'] ?? '');
        } else {
            $path = null;
        }

        $pageCount++;
    } while ($path && $pageCount < $maxPages);

    NolaCache::setWithRegistry($registryKey, $cacheKey, $allContacts, 1800); // Cache for 30 minutes
    NolaCache::setWithRegistry($registryKey, $lastGoodCacheKey, $allContacts, 604800); // Last-good fallback for 7 days

    error_log("[ghl_contacts] Successfully fetched " . count($allContacts) . " contacts (Pages: $pageCount)");
    echo json_encode(['contacts' => $allContacts, 'cached' => false]);
    exit;
}

// ── POST: create a contact ────────────────────────────────────────────────
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body']);
        exit;
    }

    $parts   = explode(' ', $body['name'] ?? '', 2);
    $ghlBody = [
        'locationId' => $locationId,
        'firstName'  => $parts[0] ?? '',
        'lastName'   => $parts[1] ?? '',
        'phone'      => $body['phone'] ?? '',
    ];

    if (!empty($body['email'])) {
        $ghlBody['email'] = $body['email'];
    }

    $resp = $ghlClient->request('POST', '/contacts/', json_encode($ghlBody));

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    // Invalidate contacts cache
    try {
        require_once __DIR__ . '/cache_helper.php';
        NolaCache::deleteRegistry("ghl_contacts_registry_{$locationId}");
    } catch (\Throwable $cacheEx) {
        error_log("[ghl_contacts] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    $data    = json_decode($resp['body'], true);
    $contact = $data['contact'] ?? $data;

    echo json_encode([
        'id'    => $contact['id'] ?? null,
        'name'  => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')),
        'phone' => $contact['phone'] ?? '',
        'email' => $contact['email'] ?? '',
    ]);
    exit;
}

// ── PUT: update a contact ─────────────────────────────────────────────────
if ($method === 'PUT') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $contactId = $body['id'] ?? $_GET['id'] ?? null;

    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing contact id']);
        exit;
    }

    $parts   = explode(' ', $body['name'] ?? '', 2);
    $ghlBody = [
        'firstName' => $parts[0] ?? '',
        'lastName'  => $parts[1] ?? '',
        'phone'     => $body['phone'] ?? '',
    ];

    if (!empty($body['email'])) {
        $ghlBody['email'] = $body['email'];
    }

    $resp = $ghlClient->request('PUT', "/contacts/{$contactId}", json_encode($ghlBody));

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    // Invalidate contacts cache
    try {
        require_once __DIR__ . '/cache_helper.php';
        NolaCache::deleteRegistry("ghl_contacts_registry_{$locationId}");
    } catch (\Throwable $cacheEx) {
        error_log("[ghl_contacts] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    $data    = json_decode($resp['body'], true);
    $contact = $data['contact'] ?? $data;
    $updatedName = trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')) ?: ($body['name'] ?? '');
    $updatedPhone = $contact['phone'] ?? ($body['phone'] ?? '');

    try {
        nola_sync_contact_conversation(
            $db,
            (string)$locationId,
            (string)$contactId,
            (string)$updatedName,
            (string)$updatedPhone,
            $body['previous_phone'] ?? null
        );
    } catch (\Throwable $syncEx) {
        error_log("[ghl_contacts] Conversation sync failed for contact {$contactId}: " . $syncEx->getMessage());
    }

    echo json_encode([
        'id'    => $contact['id'] ?? $contactId,
        'name'  => $updatedName,
        'phone' => $updatedPhone,
        'email' => $contact['email'] ?? '',
    ]);
    exit;
}

// ── DELETE: delete a contact ──────────────────────────────────────────────
if ($method === 'DELETE') {
    $contactId = $_GET['id'] ?? null;

    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing contact id']);
        exit;
    }

    $resp = $ghlClient->request('DELETE', "/contacts/{$contactId}");

    if ($resp['status'] >= 400) {
        http_response_code($resp['status']);
        echo $resp['body'];
        exit;
    }

    // Invalidate contacts cache
    try {
        require_once __DIR__ . '/cache_helper.php';
        NolaCache::deleteRegistry("ghl_contacts_registry_{$locationId}");
    } catch (\Throwable $cacheEx) {
        error_log("[ghl_contacts] Cache invalidation failed: " . $cacheEx->getMessage());
    }

    echo json_encode(['success' => $resp['status'] === 200 || $resp['status'] === 204]);
    exit;
}

// ── Fallthrough ───────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
