<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';

// Authentication — accepts X-Webhook-Secret header (frontend billing requests)
validate_api_request();

$db = get_firestore();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $scope       = $_GET['scope']       ?? 'subaccount'; // 'agency' | 'subaccount'
    $agency_id   = $_GET['agency_id']   ?? null;
    $location_id = $_GET['location_id'] ?? null;
    $month       = $_GET['month']       ?? null;          // YYYY-MM
    $page        = max(1, (int)($_GET['page'] ?? 1));
    $limit       = 50;

    $query = $db->collection('credit_transactions')->where('wallet_scope', '==', $scope);

    if ($scope === 'agency' && $agency_id) {
        $query = $query->where('account_id', '==', $agency_id);
    } elseif ($scope === 'subaccount' && $location_id) {
        $docId = (strpos($location_id, 'ghl_') === 0)
            ? $location_id
            : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $location_id);
        $query = $query->where('account_id', '==', $docId);
    }

    // Month filter — applied client-side after fetching since Firestore requires
    // composite indexes for range queries on non-indexed fields.
    $monthStart = null;
    $monthEnd   = null;
    if ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
        $monthStart = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $month . '-01 00:00:00');
        // First second of the following month
        $monthEnd   = $monthStart->modify('first day of next month');
    }

    $docs = $query->documents();
    $transactions = [];

    foreach ($docs as $doc) {
        if (!$doc->exists()) continue;
        $data = $doc->data();

        // Month filter
        if ($monthStart !== null) {
            $createdAt = $data['created_at'] ?? null;
            if ($createdAt instanceof \Google\Cloud\Core\Timestamp) {
                $dt = $createdAt->get();
                if ($dt->getTimestamp() < $monthStart->getTimestamp() || $dt->getTimestamp() >= $monthEnd->getTimestamp()) {
                    continue;
                }
            }
        }

        // Extract subaccount_id from account_id for cross-reference convenience
        $accountId = $data['account_id'] ?? '';
        $subaccountId = ($scope === 'subaccount') ? ($location_id ?? $accountId) : null;

        $transactions[] = [
            'id'             => $data['transaction_id'] ?? $doc->id(),
            'type'           => $data['type']           ?? '',
            'wallet_scope'   => $data['wallet_scope']   ?? $scope,
            'deducted_from'  => $data['deducted_from']  ?? null,
            'subaccount_id'  => $data['account_id']     ?? null,  // the doc's account_id IS the subaccount doc id
            'agency_id'      => $data['agency_id']      ?? $agency_id,
            'amount'         => $data['amount']          ?? 0,
            'balance_after'  => $data['balance_after']  ?? 0,
            'provider_cost'  => $data['provider_cost']  ?? null,
            'charged'        => $data['charged']         ?? null,
            'profit'         => $data['profit']          ?? null,
            'provider'       => $data['provider']        ?? null,
            'description'    => $data['description']     ?? '',
            'message_body'   => $data['message_body']    ?? 'Unavailable',
            'chars'          => $data['chars']           ?? 'Unavailable',
            'to_number'      => $data['to_number']       ?? 'Unavailable',
            'agency_name'    => $data['agency_name']     ?? 'Unavailable',
            'subaccount_name'=> $data['subaccount_name'] ?? 'Unavailable',
            'timestamp'      => isset($data['created_at'])
                ? $data['created_at']->get()->format('Y-m-d\TH:i:s\Z')
                : '',
        ];
    }

    // Sort descending by timestamp
    usort($transactions, function ($a, $b) {
        return strcmp($b['timestamp'], $a['timestamp']);
    });

    $total     = count($transactions);
    $offset    = ($page - 1) * $limit;
    $paginated = array_slice($transactions, $offset, $limit);

    echo json_encode([
        'transactions' => $paginated,
        'total'        => $total,
        'page'         => $page,
        'limit'        => $limit,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
