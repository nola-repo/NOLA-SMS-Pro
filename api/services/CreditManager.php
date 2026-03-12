<?php

require_once __DIR__ . '/../webhook/firestore_client.php';

use Google\Cloud\Core\Timestamp;

class CreditManager
{
    private $db;

    public function __construct()
    {
        $this->db = get_firestore();
    }

    public function get_balance($account_id = 'default')
    {
        $docRef = $this->db->collection('accounts')->document($account_id);
        $snapshot = $docRef->snapshot();
        if ($snapshot->exists()) {
            return (int)($snapshot->data()['credit_balance'] ?? 0);
        }
        return 0;
    }

    public function deduct_credits($account_id, $amount, $reference_id, $description)
    {
        if ($amount <= 0) {
            return true;
        }

        $accountRef = $this->db->collection('accounts')->document($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $ts) {
            $snapshot = $transaction->snapshot($accountRef);
            $current_balance = 0;

            if ($snapshot->exists()) {
                $current_balance = (int)($snapshot->data()['credit_balance'] ?? 0);
                $currency = $snapshot->data()['currency'] ?? 'PHP';
            }
            else {
                $current_balance = 0;
                $currency = 'PHP';
                $transaction->set($accountRef, [
                    'credit_balance' => 0,
                    'currency' => $currency,
                    'created_at' => $ts,
                    'updated_at' => $ts
                ]);
            }

            if ($current_balance < $amount) {
                throw new \Exception("Insufficient credits.");
            }

            $new_balance = $current_balance - $amount;

            $transaction->set($accountRef, [
                'credit_balance' => $new_balance,
                'updated_at' => $ts
            ], ['merge' => true]);

            $transaction->create($transactionRef, [
                'transaction_id' => $transactionRef->id(),
                'account_id' => $accountRef->id(),
                'type' => 'deduction',
                'amount' => -$amount,
                'balance_after' => $new_balance,
                'reference_id' => $reference_id,
                'description' => $description,
                'created_at' => $ts
            ]);

            return $new_balance;
        });

        return $result;
    }

    public function add_credits($account_id, $amount, $reference_id, $description, $type = 'top_up')
    {
        if ($amount <= 0) {
            return true;
        }

        $accountRef = $this->db->collection('accounts')->document($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $type, $ts) {
            $snapshot = $transaction->snapshot($accountRef);
            $current_balance = 0;
            $currency = 'PHP';

            if ($snapshot->exists()) {
                $data = $snapshot->data();
                $current_balance = (int)($data['credit_balance'] ?? 0);
                if (isset($data['currency'])) {
                    $currency = $data['currency'];
                }
            }

            $new_balance = $current_balance + $amount;

            $accountData = [
                'credit_balance' => $new_balance,
                'updated_at' => $ts
            ];

            if (!$snapshot->exists()) {
                $accountData['created_at'] = $ts;
                $accountData['currency'] = $currency;
                $transaction->set($accountRef, $accountData);
            }
            else {
                $transaction->set($accountRef, $accountData, ['merge' => true]);
            }

            $transaction->create($transactionRef, [
                'transaction_id' => $transactionRef->id(),
                'account_id' => $accountRef->id(),
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $new_balance,
                'reference_id' => $reference_id,
                'description' => $description,
                'created_at' => $ts
            ]);

            return $new_balance;
        });

        return $result;
    }
}
