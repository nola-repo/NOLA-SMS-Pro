<?php
/**
 * api/admin_list_agency_users.php
 *
 * Admin List Agency Users API
 * Returns all documents from the `agency_users` Firestore collection, enriched with agency install metadata.
 */

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/webhook/firestore_client.php';
require_once __DIR__ . '/admin_auth_helper.php';
require_once __DIR__ . '/cache_helper.php';
require_once __DIR__ . '/services/CreditManager.php';
require_once __DIR__ . '/performance_logger.php';

NolaPerformance::start('/api/admin_list_agency_users.php');

function admin_list_agency_users_format_ts($ts): ?string {
    if ($ts === null) return null;
    if ($ts instanceof \Google\Cloud\Core\Timestamp) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if (is_object($ts) && method_exists($ts, 'get')) {
        return $ts->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($ts instanceof \DateTimeInterface) {
        return $ts->format('Y-m-d\TH:i:s\Z');
    }
    if (is_string($ts)) {
        $trimmed = trim($ts);
        return $trimmed !== '' ? $trimmed : null;
    }
    return null;
}

function admin_list_agency_users_name(array $d, string $fallbackEmail): string {
    $name = trim((string)($d['name'] ?? ''));
    if ($name !== '') {
        return $name;
    }

    $joined = trim((string)($d['firstName'] ?? '') . ' ' . (string)($d['lastName'] ?? ''));
    return $joined !== '' ? $joined : $fallbackEmail;
}

function admin_list_agency_users_token_name(array $tokenData): ?string {
    foreach (['company_name', 'agency_name', 'locationName', 'location_name'] as $key) {
        $value = trim((string)($tokenData[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return null;
}

NolaPerformance::begin('auth');
require_secure_admin_auth(['super_admin', 'support', 'viewer']);
NolaPerformance::end('auth');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$cacheKey = 'admin_agency_users_list';
$bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);
if (!$bypassCache) {
    NolaPerformance::begin('cache_read');
    $cachedData = NolaCache::get($cacheKey);
    NolaPerformance::end('cache_read');
    if ($cachedData !== null) {
        NolaPerformance::cache('HIT');
        echo json_encode($cachedData);
        exit;
    }
}
NolaPerformance::cache($bypassCache ? 'BYPASS' : 'MISS');

$db = get_firestore();
$creditManager = new CreditManager();

try {
    NolaPerformance::begin('data_load');
    $agencyNames = [];
    foreach (['ghl_agency_tokens', 'ghl_tokens'] as $collection) {
        NolaPerformance::increment('firestore_queries');
        $tokenDocs = $db->collection($collection)->documents();
        foreach ($tokenDocs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            NolaPerformance::increment('documents_processed');

            $data = $doc->data();
            $isAgencyToken = $collection === 'ghl_agency_tokens' || ($data['appType'] ?? '') === 'agency';
            if (!$isAgencyToken) {
                continue;
            }

            $companyId = trim((string)($data['companyId'] ?? $data['company_id'] ?? $doc->id()));
            $name = admin_list_agency_users_token_name($data);
            if ($companyId !== '' && $name !== null) {
                $agencyNames[$companyId] = $name;
            }
        }
    }

    NolaPerformance::increment('firestore_queries');
    $usersSnap = $db->collection('agency_users')->documents();
    $agencyUsers = [];

    foreach ($usersSnap as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        NolaPerformance::increment('documents_processed');

        $d = $doc->data();
        $email = strtolower(trim((string)($d['email'] ?? '')));
        $companyId = trim((string)($d['company_id'] ?? $d['agency_id'] ?? ''));
        $companyName = trim((string)($d['company_name'] ?? $d['agency_name'] ?? ''));
        if ($companyName === '' && $companyId !== '' && isset($agencyNames[$companyId])) {
            $companyName = $agencyNames[$companyId];
        }

        if ($companyId !== '') {
            NolaPerformance::increment('firestore_document_reads', 2);
            $displayBalance = $creditManager->get_agency_balance($companyId);
        } else {
            $displayBalance = (int)($d['balance'] ?? $d['credit_balance'] ?? 0);
        }
        $subscriptionPlan = (string)($d['subscription_plan'] ?? $d['subscription']['plan'] ?? 'starter');
        $subscriptionStatus = (string)($d['subscription_status'] ?? $d['subscription']['status'] ?? 'active');
        $planSubaccountLimit = (int)($d['plan_subaccount_limit'] ?? $d['subaccount_limit'] ?? $d['subscription']['subaccount_limit'] ?? $d['subscription']['max_active_subaccounts'] ?? $d['max_active_subaccounts'] ?? 1);

        $agencyUsers[] = [
            'id' => $doc->id(),
            'name' => admin_list_agency_users_name($d, $email),
            'firstName' => $d['firstName'] ?? '',
            'lastName' => $d['lastName'] ?? '',
            'email' => $email,
            'phone' => $d['phone'] ?? '',
            'role' => $d['role'] ?? 'agency',
            'active' => !array_key_exists('active', $d) || !empty($d['active']),
            'company_id' => $companyId !== '' ? $companyId : null,
            'agency_id' => $companyId !== '' ? $companyId : null,
            'company_name' => $companyName !== '' ? $companyName : null,
            'agency_name' => $companyName !== '' ? $companyName : null,
            'balance' => $displayBalance,
            'subscription_plan' => $subscriptionPlan,
            'subscription_status' => $subscriptionStatus,
            'plan_subaccount_limit' => $planSubaccountLimit,
            'subaccount_limit' => $planSubaccountLimit,
            'max_active_subaccounts' => $planSubaccountLimit,
            'source' => $d['source'] ?? null,
            'created_at' => admin_list_agency_users_format_ts($d['created_at'] ?? $d['createdAt'] ?? null),
            'updated_at' => admin_list_agency_users_format_ts($d['updated_at'] ?? $d['updatedAt'] ?? null),
            'last_login' => admin_list_agency_users_format_ts($d['last_login'] ?? $d['lastLogin'] ?? null),
        ];
    }

    usort($agencyUsers, function ($a, $b) {
        return strcmp((string)($a['company_name'] ?? $a['email'] ?? ''), (string)($b['company_name'] ?? $b['email'] ?? ''));
    });

    $responsePayload = [
        'status' => 'success',
        'data' => $agencyUsers,
        'total' => count($agencyUsers),
    ];

    NolaPerformance::end('data_load');
    NolaPerformance::begin('cache_write');
    NolaCache::set($cacheKey, $responsePayload, 300);
    NolaPerformance::end('cache_write');
    echo json_encode($responsePayload);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Database error: ' . $e->getMessage(),
    ]);
}
