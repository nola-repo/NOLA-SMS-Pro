<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../services/CreditManager.php';

// Authentication — accepts X-Webhook-Secret header (frontend billing requests)
validate_api_request();

$db = get_firestore();

// Resolve agency_id from request params
$agency_id = $_GET['agency_id'] ?? null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $agency_id = $input['agency_id'] ?? $agency_id;
    $action = $input['action'] ?? $_GET['action'] ?? '';
} else {
    $action = $_GET['action'] ?? '';
}

if (!$agency_id) {
    http_response_code(400);
    echo json_encode(['error' => 'agency_id is required.']);
    exit;
}

$agencyWalletRef = $db->collection('agency_wallet')->document($agency_id);
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $snapshot = $agencyWalletRef->snapshot();
    $data = $snapshot->exists() ? $snapshot->data() : [];
    
    echo json_encode([
        'balance'                    => $data['balance'] ?? 0,
        'auto_recharge_enabled'      => $data['auto_recharge_enabled'] ?? false,
        'auto_recharge_amount'       => $data['auto_recharge_amount'] ?? 500,
        'auto_recharge_threshold'    => $data['auto_recharge_threshold'] ?? 100,
        'enforce_master_balance_lock' => $data['enforce_master_balance_lock'] ?? false,
        'updated_at'                 => isset($data['updated_at']) ? $data['updated_at']->get()->format('Y-m-d\TH:i:s\Z') : null,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'set_auto_recharge') {
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : false;
        $amount = isset($input['amount']) ? (int)$input['amount'] : 500;
        $threshold = isset($input['threshold']) ? (int)$input['threshold'] : 100;

        $agencyWalletRef->set([
            'auto_recharge_enabled' => $enabled,
            'auto_recharge_amount'  => $amount,
            'auto_recharge_threshold' => $threshold,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'set_master_lock') {
        $enabled = isset($input['enabled']) ? (bool)$input['enabled'] : false;

        $agencyWalletRef->set([
            'enforce_master_balance_lock' => $enabled,
            'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTime())
        ], ['merge' => true]);

        echo json_encode(['success' => true]);
        exit;
    }

    if ($action === 'gift') {
        $location_id = $input['location_id'] ?? '';
        $amount = isset($input['amount']) ? (int)$input['amount'] : 0;
        $note = $input['note'] ?? 'Agency Gift';

        if (!$location_id || $amount <= 0) {
            http_response_code(400);
            echo json_encode(['error' => 'location_id and valid amount are required']);
            exit;
        }

        $docId = (strpos($location_id, 'ghl_') === 0) ? $location_id : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $location_id);
        $subaccountRef = $db->collection('integrations')->document($docId);
        
        $txRefAgency = $db->collection('credit_transactions')->newDocument();
        $txRefSub = $db->collection('credit_transactions')->newDocument();
        
        $now = new \DateTimeImmutable();
        $ts = new \Google\Cloud\Core\Timestamp($now);

        try {
            $result = $db->runTransaction(function ($transaction) use ($agencyWalletRef, $subaccountRef, $txRefAgency, $txRefSub, $amount, $note, $ts) {
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
                } else {
                    $transaction->set($subaccountRef, $subData, ['merge' => true]);
                }

                $transaction->create($txRefAgency, [
                    'transaction_id' => $txRefAgency->id(),
                    'account_id'     => $agencyWalletRef->id(),
                    'wallet_scope'   => 'agency',
                    'type'           => 'credit_distribution',
                    'deducted_from'  => 'agency',
                    'amount'         => -$amount,
                    'balance_after'  => $new_agency_balance,
                    'description'    => $note,
                    'created_at'     => $ts,
                ]);

                $transaction->create($txRefSub, [
                    'transaction_id' => $txRefSub->id(),
                    'account_id' => $subaccountRef->id(),
                    'wallet_scope' => 'subaccount',
                    'type' => 'gift_received',
                    'amount' => $amount,
                    'balance_after' => $new_sub_balance,
                    'description' => $note,
                    'created_at' => $ts
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
        } catch (\Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }

        exit;
    }
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
