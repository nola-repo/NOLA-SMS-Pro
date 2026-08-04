<?php
/**
 * api/admin_list_users.php
 *
 * Admin List Users API
 * Returns all documents from the `users` Firestore collection, enriched with integration data and provider balance.
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/jwt_helper.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/services/AgencyNameResolver.php';
require_once __DIR__ . '/services/SemaphoreBalanceFetcher.php';
require_once __DIR__ . '/performance_logger.php';

NolaPerformance::start('/api/admin_list_users.php');

// ─── JWT Auth Guard ───────────────────────────────────────────────────────────
function require_admin_auth(): array {
    return require_secure_admin_auth();
}

// ─── Helper: format Firestore timestamp ──────────────────────────────────────
function format_ts($ts): ?string {
    if ($ts === null) return null;
    if (is_object($ts) && method_exists($ts, 'get')) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    return null;
}

// ─── Main Logic ──────────────────────────────────────────────────────────────
NolaPerformance::begin('auth');
$claims = require_secure_admin_auth();
NolaPerformance::end('auth');

$cacheKey = "admin_users_list";
NolaPerformance::begin('cache_read');
$cachedData = NolaCache::get($cacheKey);
NolaPerformance::end('cache_read');
if ($cachedData !== null) {
    NolaPerformance::cache('HIT');
    echo json_encode($cachedData);
    exit;
}
NolaPerformance::cache('MISS');

$db             = get_firestore();
$balanceFetcher = new SemaphoreBalanceFetcher();

try {
    NolaPerformance::begin('data_load');
    // High-performance optimization: pre-fetch integrations and ghl_tokens to avoid O(N) queries
    NolaPerformance::increment('firestore_queries');
    $integrationsSnap = $db->collection('integrations')->limit(500)->documents();
    $integrationMap = [];
    foreach ($integrationsSnap as $doc) {
        if ($doc->exists()) {
            NolaPerformance::increment('documents_processed');
            $integrationMap[$doc->id()] = $doc->data();
        }
    }

    NolaPerformance::increment('firestore_queries');
    $ghlTokensSnap = $db->collection('ghl_tokens')->limit(500)->documents();
    $ghlTokenMap = [];
    foreach ($ghlTokensSnap as $doc) {
        if ($doc->exists()) {
            NolaPerformance::increment('documents_processed');
            $ghlTokenMap[$doc->id()] = $doc->data();
        }
    }

    $agencyNameMap = [];
    foreach (['agency_users', 'agencies'] as $agencyCollection) {
        try {
            NolaPerformance::increment('firestore_queries');
            $agencySnap = $db->collection($agencyCollection)->limit(500)->documents();
            foreach ($agencySnap as $agencyDoc) {
                if (!$agencyDoc->exists()) continue;
                NolaPerformance::increment('documents_processed');

                $agencyData = $agencyDoc->data();
                $companyId = AgencyNameResolver::companyId($agencyData, $agencyDoc->id());
                $agencyName = $agencyCollection === 'agencies'
                    ? AgencyNameResolver::agencyName($agencyData)
                    : AgencyNameResolver::agencyUserCompanyName($agencyData);

                if ($companyId !== '' && $agencyName !== '') {
                    $agencyNameMap[$companyId] = $agencyName;
                }
            }
        } catch (Exception $e) {
            error_log('[admin_list_users] Agency prefetch failed for ' . $agencyCollection . ': ' . $e->getMessage());
        }
    }

    // Fetch all users
    NolaPerformance::increment('firestore_queries');
    $usersSnap = $db->collection('users')->documents();
    $usersList = [];

    foreach ($usersSnap as $doc) {
        if (!$doc->exists()) continue;
        NolaPerformance::increment('documents_processed');
        $d = $doc->data();

        $locId = $d['active_location_id'] ?? $d['location_id'] ?? '';
        $locationName = 'Unknown';
        $approvedSenderId = null;
        $freeUsageCount = 0;
        $freeCreditsTotal = 10;
        $intData = null;

        if (!empty($locId)) {
            // Check ghl_tokens first
            if (isset($ghlTokenMap[$locId])) {
                $locationName = $ghlTokenMap[$locId]['location_name'] ?? $ghlTokenMap[$locId]['locationName'] ?? 'Unknown';
            }

            // Check integrations
            $intDocId = 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId);
            $intData = $integrationMap[$intDocId] ?? $integrationMap[$locId] ?? null;
            if ($intData) {
                if ($locationName === 'Unknown' || empty($locationName)) {
                    $locationName = $intData['location_name'] ?? 'Unknown';
                }
                $approvedSenderId = $intData['approved_sender_id'] ?? null;
                $freeUsageCount   = (int)($intData['free_usage_count'] ?? 0);
                $freeCreditsTotal = (int)($intData['free_credits_total'] ?? 10);
            }
        }

        // Split name if first/last names are empty but full name exists
        $firstName = $d['firstName'] ?? '';
        $lastName  = $d['lastName'] ?? '';
        $fullName  = $d['name'] ?? '';
        if (empty($firstName) && empty($lastName) && !empty($fullName)) {
            $parts = preg_split('/\s+/', trim((string)$fullName));
            $firstName = $parts[0] ?? '';
            $lastName  = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
        }

        $companyId = $d['company_id'] ?? $d['companyId'] ?? null;
        $agencyName = AgencyNameResolver::forUser($d, $agencyNameMap);

        $userItem = [
            'id'                 => $doc->id(),
            'name'               => $fullName,
            'firstName'          => $firstName,
            'lastName'           => $lastName,
            'email'              => $d['email'] ?? '',
            'phone'              => $d['phone'] ?? '',
            'role'               => $d['role'] ?? 'user',
            'active'             => !array_key_exists('active', $d) || !empty($d['active']),
            'location_id'        => !empty($locId) ? $locId : null,
            'location_name'      => $locationName,
            'company_id'         => $companyId,
            'company_name'       => $agencyName !== '' ? $agencyName : 'Agency name unavailable',
            'agency_name'        => $agencyName !== '' ? $agencyName : 'Agency name unavailable',
            'credit_balance'     => (int)($d['credit_balance'] ?? 0),
            'free_usage_count'   => $freeUsageCount,
            'free_credits_total' => $freeCreditsTotal,
            'approved_sender_id' => $approvedSenderId,
            'source'             => $d['source'] ?? 'marketplace_install',
            'created_at'         => format_ts($d['created_at'] ?? null)
        ];

        $usersList[] = $balanceFetcher->enrichSubaccount($userItem, $intData);
    }

    $responsePayload = [
        'status' => 'success',
        'data'   => $usersList,
        'total'  => count($usersList)
    ];
    NolaPerformance::end('data_load');
    NolaPerformance::begin('cache_write');
    NolaCache::set($cacheKey, $responsePayload, 300); // 5-minute TTL
    NolaPerformance::end('cache_write');
    echo json_encode($responsePayload);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
