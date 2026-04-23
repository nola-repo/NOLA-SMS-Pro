<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/CreditManager.php';

// Authentication — accepts X-Webhook-Secret header (frontend billing requests)
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $agency_id = $_GET['agency_id'] ?? null;
    $status = $_GET['status'] ?? null;

    if (!$agency_id) {
        http_response_code(400);
        echo json_encode(['error' => 'agency_id required']);
        exit;
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
        $requests[] = [
            'request_id' => $data['request_id'] ?? $doc->id(),
            'location_id' => $data['location_id'] ?? '',
            'location_name' => $data['location_name'] ?? '',
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

    echo json_encode(['requests' => $requests]);
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

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'approve') {
        $agencyWalletRef = $db->collection('agency_wallet')->document($agency_id);
        $docId = (strpos($location_id, 'ghl_') === 0) ? $location_id : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $location_id);
        $subaccountRef = $db->collection('integrations')->document($docId);

        $txRefAgency = $db->collection('credit_transactions')->newDocument();
        $txRefSub = $db->collection('credit_transactions')->newDocument();

        $ts = new \Google\Cloud\Core\Timestamp(new \DateTime());

        try {
            $result = $db->runTransaction(function ($transaction) use ($agencyWalletRef, $subaccountRef, $requestRef, $txRefAgency, $txRefSub, $amount, $ts, $userId) {
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
                    'account_id' => $agencyWalletRef->id(),
                    'wallet_scope' => 'agency',
                    'type' => 'credit_distribution',
                    'deducted_from' => 'agency',
                    'amount' => -$amount,
                    'balance_after' => $new_agency_balance,
                    'description' => 'Approved credit request',
                    'created_at' => $ts,
                ]);

                $transaction->create($txRefSub, [
                    'transaction_id' => $txRefSub->id(),
                    'account_id' => $subaccountRef->id(),
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
