<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/auth_helper.php';
// This global endpoint does not require a specific agency ID, but still requires valid auth
validate_agency_request(false);

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../cache_helper.php';
$db = get_firestore();

$cacheKey = 'agency_all_active_subaccounts';
$cacheTtl = 120;
$bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);
if (!$bypassCache) {
    $cachedData = NolaCache::get($cacheKey);
    if ($cachedData !== null) {
        NolaCache::sendApiCacheHeaders($cacheTtl, true);
        echo json_encode($cachedData);
        exit;
    }
}

$active_subaccounts = [];
$query = $db->collection('agency_subaccounts')->where('toggle_enabled', '=', true);
$documents = $query->documents();
foreach ($documents as $document) {
    if ($document->exists()) {
        $data = $document->data();
        $active_subaccounts[] = $data;
    }
}

$responsePayload = ['status' => 'success', 'active_subaccounts' => $active_subaccounts];
NolaCache::set($cacheKey, $responsePayload, $cacheTtl);
NolaCache::sendApiCacheHeaders($cacheTtl, false);
echo json_encode($responsePayload);
