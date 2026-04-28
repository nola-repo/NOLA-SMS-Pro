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
 *   401  { error: "OAuth token invalid or expired" }
 *   404  { error: "No OAuth token found for this location" }
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

// ── 2. Initialize GHL Client (Handles Token Lookup & Refresh) ──────────────
$db = get_firestore();

try {
    $ghlClient = new GhlClient($db, (string)$locationId);
} catch (\Exception $e) {
    error_log("[ghl_contacts] Client initialization failed: " . $e->getMessage());
    http_response_code(404);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}

// ── 3. Handle CRUD Requests ───────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ── GET: fetch contacts (with pagination) ─────────────────────────────────
if ($method === 'GET') {
    $allContacts = [];
    $path        = '/contacts/?locationId=' . urlencode($locationId) . '&limit=100';
    $pageCount   = 0;
    $maxPages    = 20; // Safety cap: sync up to 2,000 contacts at once

    do {
        $resp = $ghlClient->request('GET', $path);

        if ($resp['status'] >= 400) {
            if (!empty($allContacts)) break; // Return what we have so far
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

    error_log("[ghl_contacts] Successfully fetched " . count($allContacts) . " contacts (Pages: $pageCount)");
    echo json_encode(['contacts' => $allContacts]);
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

    $data    = json_decode($resp['body'], true);
    $contact = $data['contact'] ?? $data;

    echo json_encode([
        'id'    => $contact['id'] ?? $contactId,
        'name'  => trim(($contact['firstName'] ?? '') . ' ' . ($contact['lastName'] ?? '')),
        'phone' => $contact['phone'] ?? '',
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

    echo json_encode(['success' => $resp['status'] === 200 || $resp['status'] === 204]);
    exit;
}

// ── Fallthrough ───────────────────────────────────────────────────────────
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
