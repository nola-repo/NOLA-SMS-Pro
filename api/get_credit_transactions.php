<?php

ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/cors.php';
header('Content-Type: application/json');

require __DIR__ . '/webhook/firestore_client.php';
require __DIR__ . '/auth_helpers.php';

$db = get_firestore();

// Authentication: Support Webhook-Secret, Admin JWT, or Agency JWT
$authenticated = false;

// 1. Try Webhook Secret
$receivedSecret = $_SERVER['HTTP_X_WEBHOOK_SECRET'] ?? '';
if (!$receivedSecret) {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    foreach ($headers as $key => $value) {
        if (strcasecmp($key, 'X-Webhook-Secret') === 0) {
            $receivedSecret = $value;
            break;
        }
    }
}
if (!$receivedSecret) {
    $receivedSecret = $_GET['secret'] ?? $_GET['token'] ?? '';
}
$expectedSecret = getenv('WEBHOOK_SECRET');
if ($expectedSecret !== false && trim((string)$expectedSecret) !== '' && $receivedSecret !== '' && hash_equals($expectedSecret, (string)$receivedSecret)) {
    $authenticated = true;
}

// 2. Try Admin JWT or Agency JWT
if (!$authenticated) {
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!$authHeader) {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, 'Authorization') === 0) {
                $authHeader = $value;
                break;
            }
        }
    }

    if ($authHeader && preg_match('/^Bearer\s+(.+)$/i', trim((string)$authHeader), $matches)) {
        $token = $matches[1];
        $secret = getenv('JWT_SECRET');
        if ($secret !== false && trim((string)$secret) !== '') {
            require_once __DIR__ . '/jwt_helper.php';
            $claims = jwt_verify($token, (string)$secret);
            if ($claims) {
                $role = (string)($claims['role'] ?? '');
                if (in_array($role, ['super_admin', 'support', 'viewer'], true)) {
                    $email = strtolower(trim((string)($claims['email'] ?? $claims['username'] ?? '')));
                    if ($email !== '') {
                        $adminRef = $db->collection('admins')->document($email);
                        $snap = $adminRef->snapshot();
                        if ($snap->exists() && !empty($snap->data()['active'])) {
                            $authenticated = true;
                        } else {
                            $matchesDocs = $db->collection('admins')->where('email', '=', $email)->limit(1)->documents();
                            foreach ($matchesDocs as $doc) {
                                if ($doc->exists() && !empty($doc->data()['active'])) {
                                    $authenticated = true;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    $jwtCtx = auth_get_optional_jwt_context($db);
                    if ($jwtCtx !== null) {
                        $locId = get_ghl_location_id();
                        if ($locId) {
                            auth_assert_ghl_api_location_allowed($db, $jwtCtx, $locId);
                            $authenticated = true;
                        }
                    }
                }
            }
        }
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'status'  => 'error',
        'error'   => 'Unauthorized Access',
        'message' => 'Unauthorized Access',
    ], JSON_PRETTY_PRINT);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        http_response_code(405);
        echo json_encode([
            'success' => false,
            'error'   => 'Method not allowed',
        ], JSON_PRETTY_PRINT);
        exit;
    }

    $locId = get_ghl_location_id();
    // ROBUST PREFIX HANDLING: Must match CreditManager's ghl_ prefixing logic
    $accountId = $locId ? ('ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$locId)) : 'default';

    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    $month = $_GET['month'] ?? null;
    
    require_once __DIR__ . '/cache_helper.php';
    $paramsHash = md5(serialize([$accountId, $limit, $month]));
    $cacheKey = "transactions_list_{$accountId}_{$paramsHash}";
    $registryKey = "credits_registry_{$locId}";

    if ($locId) {
        $cachedData = NolaCache::get($cacheKey);
        if ($cachedData !== null) {
            $cachedData['cached'] = true;
            echo json_encode($cachedData, JSON_PRETTY_PRINT);
            exit;
        }
    }

    $monthStart = null;
    $monthEnd   = null;
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');
        $monthEnd   = $monthStart->modify('first day of next month');
    }
    
    // Query credit_transactions for this account, sorted by newest first
    $transactionsRef = $db->collection('credit_transactions');
    $query = $transactionsRef->where('account_id', '=', $accountId);
    
    if ($monthStart !== null) {
        $query = $query->where('created_at', '>=', new \Google\Cloud\Core\Timestamp($monthStart))
                       ->where('created_at', '<', new \Google\Cloud\Core\Timestamp($monthEnd));
    }
    
    $query = $query->orderBy('created_at', 'DESC')
                   ->limit($limit);
                             
    $documents = $query->documents();
    
    $transactions = [];
    foreach ($documents as $doc) {
        if ($doc->exists()) {
            $data = $doc->data();

            // Format timestamp if present
            if (isset($data['created_at']) && $data['created_at'] instanceof \Google\Cloud\Core\Timestamp) {
                $data['created_at'] = $data['created_at']->get()->format('Y-m-d\TH:i:s\Z');
            }
            
            $transactions[] = $data;
        }
    }

    $responsePayload = [
        'success'      => true,
        'status'       => 'success',
        'account_id'   => $accountId,
        'count'        => count($transactions),
        'data'         => $transactions,
        'transactions' => $transactions,
    ];

    if ($locId) {
        NolaCache::setWithRegistry($registryKey, $cacheKey, $responsePayload, 300); // Cache for 5 minutes
    }

    $responsePayload['cached'] = false;
    echo json_encode($responsePayload, JSON_PRETTY_PRINT);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Failed to fetch credit transactions',
        'message' => $e->getMessage(),
    ], JSON_PRETTY_PRINT);
}
