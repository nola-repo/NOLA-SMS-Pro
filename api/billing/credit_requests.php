<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../cache_helper.php';

$db = get_firestore();

function nola_resolve_credit_request_location_name($db, string $locationId, ?string $fallback = ''): string
{
    $fallback = trim((string)$fallback);
    if ($fallback !== '' && strtolower($fallback) !== 'unnamed location') {
        return $fallback;
    }

    $candidates = array_values(array_unique(array_filter([
        $locationId,
        CreditManager::integration_doc_id_for_location($locationId),
        strpos($locationId, 'ghl_') === 0 ? substr($locationId, 4) : 'ghl_' . $locationId,
    ])));

    foreach ($candidates as $docId) {
        foreach (['integrations', 'ghl_tokens', 'agency_subaccounts'] as $collectionName) {
            try {
                $snap = $db->collection($collectionName)->document($docId)->snapshot();
                if (!$snap->exists()) {
                    continue;
                }
                $data = $snap->data();
                $name = trim((string)($data['location_name'] ?? $data['locationName'] ?? $data['name'] ?? $data['business_name'] ?? ''));
                if ($name !== '' && strtolower($name) !== 'unnamed location') {
                    return $name;
                }
            } catch (\Throwable $e) {
                error_log("[credit_requests] Location name lookup failed for {$collectionName}/{$docId}: " . $e->getMessage());
            }
        }
    }

    return $fallback !== '' ? $fallback : $locationId;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $agency_id = $_GET['agency_id'] ?? null;
    $status = $_GET['status'] ?? null;

    if (!$agency_id) {
        http_response_code(400);
        echo json_encode(['error' => 'agency_id required']);
        exit;
    }

    auth_assert_agency_billing_allowed($db, (string)$agency_id);

    $cacheKey = 'credit_requests_' . md5((string)$agency_id . '|' . (string)$status);
    $cacheTtl = 60;
    $bypassCache = isset($_GET['refresh']) || isset($_GET['bypass_cache']);
    if (!$bypassCache) {
        $cachedData = NolaCache::get($cacheKey);
        if ($cachedData !== null) {
            NolaCache::sendApiCacheHeaders($cacheTtl, true);
            echo json_encode($cachedData);
            exit;
        }
    }

    $query = $db->collection('credit_requests')->where('agency_id', '==', $agency_id);
    if ($status && $status !== 'all') {
        $query = $query->where('status', '==', $status);
    }

    // Using orderby requires a composite index, we will do it clientside or simple query mapping
    $docs = $query->documents();
    $requests = [];
    foreach ($docs as $doc) {
        $data = $doc->data();
        $locationId = (string)($data['location_id'] ?? '');
        $requests[] = [
            'request_id' => $data['request_id'] ?? $doc->id(),
            'location_id' => $locationId,
            'location_name' => nola_resolve_credit_request_location_name($db, $locationId, $data['location_name'] ?? ''),
            'amount' => $data['amount'] ?? 0,
            'note' => $data['note'] ?? '',
            'status' => $data['status'] ?? '',
            'created_at' => isset($data['created_at']) ? $data['created_at']->get()->format('Y-m-d\TH:i:s\Z') : null,
            'resolved_at' => isset($data['resolved_at']) ? $data['resolved_at']->get()->format('Y-m-d\TH:i:s\Z') : null
        ];
    }

    usort($requests, function ($a, $b) {
        return strcmp($b['created_at'], $a['created_at']);
    });

    $responsePayload = ['requests' => $requests];
    NolaCache::setWithRegistry('credit_requests_registry_' . $agency_id, $cacheKey, $responsePayload, $cacheTtl);
    NolaCache::sendApiCacheHeaders($cacheTtl, false);
    echo json_encode($responsePayload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? $_GET['action'] ?? '';
    $request_id = $input['request_id'] ?? '';

    if (!$request_id) {
        http_response_code(400);
        echo json_encode(['error' => 'request_id is required']);
        exit;
    }

    $requestRef = $db->collection('credit_requests')->document($request_id);
    $requestSnap = $requestRef->snapshot();
    if (!$requestSnap->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'Request not found']);
        exit;
    }

    $requestData = $requestSnap->data();
    auth_assert_agency_billing_allowed($db, (string)($requestData['agency_id'] ?? ''));

    if (($requestData['status'] ?? 'pending') !== 'pending') {
        error_log('[credit_requests] Ignore duplicate attempt to resolve ' . $request_id);
        echo json_encode(['success' => false, 'error' => 'Request already processed']);
        exit; // don't re-process
    }

    $agency_id = $requestData['agency_id'];
    $location_id = $requestData['location_id'];
    $amount = (int)($requestData['amount'] ?? 0);
    $userId = 'agency_webhook';

    if ($action === 'deny') {
        $requestRef->set([
            'status' => 'denied',
            'resolved_at' => new \Google\Cloud\Core\Timestamp(new \DateTime()),
            'resolved_by' => $userId
        ], ['merge' => true]);

        NolaCache::deleteRegistry('credit_requests_registry_' . $agency_id);
        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'approve') {
        $creditManager = new CreditManager();
        $agencyWalletRef = $creditManager->resolveAgencyBalanceDocument($agency_id);
        $subaccountRef = $creditManager->resolveSubaccountBalanceDocument($location_id);

        $txRefAgency = $db->collection('credit_transactions')->newDocument();
        $txRefSub = $db->collection('credit_transactions')->newDocument();

        $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());

        try {
            $result = $db->runTransaction(function ($transaction) use ($agencyWalletRef, $subaccountRef, $requestRef, $txRefAgency, $txRefSub, $amount, $ts, $userId, $agency_id, $location_id) {
                $snapAgency = $transaction->snapshot($agencyWalletRef);
                $snapSub = $transaction->snapshot($subaccountRef);

                $agency_balance = $snapAgency->exists() ? (int)($snapAgency->data()['balance'] ?? 0) : 0;
                $sub_balance = $snapSub->exists() ? (int)($snapSub->data()['credit_balance'] ?? 0) : 0;

                if ($agency_balance < $amount) {
                    return ['success' => false, 'error' => 'Insufficient agency credits'];
                }

                $new_agency_balance = $agency_balance - $amount;
                $new_sub_balance = $sub_balance + $amount;

                $transaction->set($agencyWalletRef, [
                    'balance' => $new_agency_balance,
                    'updated_at' => $ts
                ], ['merge' => true]);

                $subData = [
                    'credit_balance' => $new_sub_balance,
                    'updated_at' => $ts
                ];
                if (!$snapSub->exists()) {
                    $subData['created_at'] = $ts;
                    $transaction->set($subaccountRef, $subData);
                }
                else {
                    $transaction->set($subaccountRef, $subData, ['merge' => true]);
                }

                $transaction->set($requestRef, [
                    'status' => 'approved',
                    'resolved_at' => $ts,
                    'resolved_by' => $userId
                ], ['merge' => true]);

                $transaction->create($txRefAgency, [
                    'transaction_id' => $txRefAgency->id(),
                    'account_id' => $agency_id,
                    'wallet_scope' => 'agency',
                    'type' => 'credit_distribution',
                    'deducted_from' => 'agency',
                    'amount' => -$amount,
                    'balance_after' => $new_agency_balance,
                    'description' => 'Approved credit request',
                    'created_at' => $ts,
                ]);

                $subTxAccountId = CreditManager::integration_doc_id_for_location($location_id);

                $transaction->create($txRefSub, [
                    'transaction_id' => $txRefSub->id(),
                    'account_id' => $subTxAccountId,
                    'wallet_scope' => 'subaccount',
                    'type' => 'request_approved',
                    'deducted_from' => 'agency',
                    'amount' => $amount,
                    'balance_after' => $new_sub_balance,
                    'description' => 'Approved credit request',
                    'created_at' => $ts,
                ]);

                return [
                'success' => true,
                'agency_balance' => $new_agency_balance,
                'subaccount_balance' => $new_sub_balance
                ];
            });

            if (!$result['success']) {
                http_response_code(400);
            } else {
                NolaCache::invalidateAgencyDashboard($agency_id);
                NolaCache::deleteRegistry('credit_requests_registry_' . $agency_id);
                NolaCache::deleteRegistry("credits_registry_{$location_id}");
                try {
                    require_once __DIR__ . '/../services/NotificationService.php';
                    NotificationService::notifyTopUpSuccess($db, $location_id, $amount, (int)$result['subaccount_balance']);
                } catch (\Throwable $e) {
                    error_log("[credit_requests.php] Failed to send top up success notification: " . $e->getMessage());
                }
            }
            echo json_encode($result);
        }
        catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }

        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
