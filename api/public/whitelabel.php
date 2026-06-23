<?php

/**
 * Public Whitelabel Endpoint
 *
 * Returns branding data (logo, color, company name) for a given custom domain.
 * This endpoint is PUBLIC; no authentication required.
 *
 * Usage: GET /api/public/whitelabel?domain=app.agencydomain.com
 */

require_once __DIR__ . '/../cors.php';
require_once __DIR__ . '/../cache_helper.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

function whitelabel_default_payload(): array
{
    return [
        'status' => 'success',
        'branding' => [
            'company_name' => 'NOLA SMS Pro',
            'logo_url' => '',
            'primary_color' => '#2b83fa',
            'agency_id' => null,
        ],
    ];
}

$domain = trim($_GET['domain'] ?? '');

if (empty($domain)) {
    $defaultPayload = whitelabel_default_payload();
    NolaCache::sendApiCacheHeaders(3600, 'BYPASS');
    echo json_encode(NolaCache::withCacheMeta($defaultPayload, 3600, 'BYPASS', 'domain'));
    exit;
}

$domain = strtolower(preg_replace('/[^a-z0-9.\-]/', '', $domain));
$cacheTtl = 1800;
$cacheKey = 'whitelabel_domain_' . md5($domain);

$cachedPayload = NolaCache::get($cacheKey);
if (is_array($cachedPayload)) {
    NolaCache::sendApiCacheHeaders($cacheTtl, true);
    echo json_encode(NolaCache::withCacheMeta($cachedPayload, $cacheTtl, true, 'domain'));
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

try {
    $docRef = $db->collection('whitelabel_domains')->document($domain);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        $responsePayload = whitelabel_default_payload();
        NolaCache::set($cacheKey, $responsePayload, $cacheTtl);
        NolaCache::sendApiCacheHeaders($cacheTtl, false);
        echo json_encode(NolaCache::withCacheMeta($responsePayload, $cacheTtl, false, 'domain'));
        exit;
    }

    $data = $snapshot->data();
    $responsePayload = [
        'status' => 'success',
        'branding' => [
            'company_name' => $data['company_name'] ?? 'NOLA SMS Pro',
            'logo_url' => $data['logo_url'] ?? '',
            'primary_color' => $data['primary_color'] ?? '#2b83fa',
            'agency_id' => $data['agency_id'] ?? null,
        ],
    ];

    NolaCache::set($cacheKey, $responsePayload, $cacheTtl);
    NolaCache::sendApiCacheHeaders($cacheTtl, false);
    echo json_encode(NolaCache::withCacheMeta($responsePayload, $cacheTtl, false, 'domain'));
} catch (Exception $e) {
    error_log('Whitelabel endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
