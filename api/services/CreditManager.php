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

    /**
     * Calculates the number of credits required for a message based on length and encoding.
     * 
     * GSM-7 Encoding:
     * - Single segment: up to 160 characters
     * - Multi-segment: 153 characters per segment
     * 
     * Unicode Encoding (UCS-2):
     * - Single segment: up to 70 characters
     * - Multi-segment: 67 characters per segment
     * 
     * @param string $message The message content
     * @param int $num_recipients Number of recipients
     * @return int Total credits required
     */
    public static function calculateRequiredCredits(string $message, int $num_recipients = 1): int
    {
        if (empty($message)) {
            return 0;
        }

        // Detection of Unicode characters
        // GSM-7 basic character set + extension
        $gsm7_basic = '@£$¥èéùìòÇ' . "\n" . 'Øø' . "\r" . 'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
        $gsm7_extension = '^{}\\[]~|€';

        $is_unicode = false;
        $gsm7_length = 0;

        // Check if message is mb_string compatible, otherwise use strlen
        $len = mb_strlen($message, 'UTF-8');

        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($message, $i, 1, 'UTF-8');

            if (mb_strpos($gsm7_basic, $char, 0, 'UTF-8') !== false) {
                $gsm7_length += 1;
            }
            elseif (mb_strpos($gsm7_extension, $char, 0, 'UTF-8') !== false) {
                $gsm7_length += 2; // Extended characters count as 2
            }
            else {
                $is_unicode = true;
                break;
            }
        }

        if ($is_unicode) {
            // Unicode limits
            if ($len <= 70) {
                $segments = 1;
            }
            else {
                $segments = ceil($len / 67);
            }
        }
        else {
            // GSM-7 limits
            if ($gsm7_length <= 160) {
                $segments = 1;
            }
            else {
                $segments = ceil($gsm7_length / 153);
            }
        }

        return (int)max(1, $segments) * $num_recipients;
    }

    public function get_balance($account_id = 'default')
    {
        $docRef = $this->get_account_ref($account_id);
        $snapshot = $docRef->snapshot();
        if ($snapshot->exists()) {
            return (int)($snapshot->data()['credit_balance'] ?? 0);
        }
        return 0;
    }

    public function get_agency_balance($agency_id)
    {
        $docRef = $this->get_agency_ref($agency_id);
        $snapshot = $docRef->snapshot();
        if ($snapshot->exists()) {
            return (int)($snapshot->data()['balance'] ?? 0);
        }
        return 0;
    }

    public function deduct_both_wallets($agency_id, $location_id, $amount, $reference_id, $description)
    {
        if ($amount <= 0) {
            return true;
        }

        $agencyRef = $this->get_agency_ref($agency_id);
        $subaccountRef = $this->get_account_ref($location_id);
        $transactionRefAgency = $this->db->collection('credit_transactions')->newDocument();
        $transactionRefSub = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($agencyRef, $subaccountRef, $transactionRefAgency, $transactionRefSub, $amount, $reference_id, $description, $ts) {
            $snapAgency = $transaction->snapshot($agencyRef);
            $snapSub = $transaction->snapshot($subaccountRef);

            $agency_balance = $snapAgency->exists() ? (int)($snapAgency->data()['balance'] ?? 0) : 0;
            $sub_balance = $snapSub->exists() ? (int)($snapSub->data()['credit_balance'] ?? 0) : 0;

            if ($agency_balance < $amount || $sub_balance < $amount) {
                // Return payload mapping out errors
                return [
                    'success' => false,
                    'error' => 'insufficient_credits',
                    'agency_balance' => $agency_balance,
                    'subaccount_balance' => $sub_balance
                ];
            }

            $new_agency_balance = $agency_balance - $amount;
            $new_sub_balance = $sub_balance - $amount;

            // Deduct agency
            $transaction->set($agencyRef, [
                'balance' => $new_agency_balance,
                'updated_at' => $ts
            ], ['merge' => true]);

            // Deduct subaccount
            $transaction->set($subaccountRef, [
                'credit_balance' => $new_sub_balance,
                'updated_at' => $ts
            ], ['merge' => true]);

            // Agency transaction log
            $transaction->create($transactionRefAgency, [
                'transaction_id' => $transactionRefAgency->id(),
                'account_id' => $agencyRef->id(),
                'wallet_scope' => 'agency',
                'type' => 'deduction',
                'amount' => -$amount,
                'balance_after' => $new_agency_balance,
                'reference_id' => $reference_id,
                'description' => $description,
                'created_at' => $ts
            ]);

            // Subaccount transaction log
            $transaction->create($transactionRefSub, [
                'transaction_id' => $transactionRefSub->id(),
                'account_id' => $subaccountRef->id(),
                'wallet_scope' => 'subaccount',
                'type' => 'deduction',
                'amount' => -$amount,
                'balance_after' => $new_sub_balance,
                'reference_id' => $reference_id,
                'description' => $description,
                'created_at' => $ts
            ]);

            return ['success' => true];
        });

        if (is_array($result) && !($result['success'] ?? false)) {
            // Re-throw so caller can catch it and act
            throw new \Exception(json_encode($result));
        }

        return $result;
    }

    public function deduct_credits($account_id, $amount, $reference_id, $description)
    {
        if ($amount <= 0) {
            return true;
        }

        // Optimistic upfront check (prevents massive overrun if already low)
        $current_bal = $this->get_balance($account_id);
        if ($current_bal < $amount) {
            throw new \Exception("Insufficient credits.");
        }

        $accountRef = $this->get_account_ref($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $ts) {
            $snapshot = $transaction->snapshot($accountRef);
            $current_balance = 0;

            if ($snapshot->exists()) {
                $current_balance = (int)($snapshot->data()['credit_balance'] ?? 0);
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
                'wallet_scope' => 'subaccount',
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

    public function add_credits($account_id, $amount, $reference_id, $description, $type = 'top_up', $wallet_scope = 'subaccount')
    {
        if ($amount <= 0) {
            return true;
        }

        $accountRef = $wallet_scope === 'agency' ? $this->get_agency_ref($account_id) : $this->get_account_ref($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $type, $wallet_scope, $ts) {
            $snapshot = $transaction->snapshot($accountRef);
            $current_balance = 0;
            $currency = 'PHP';
            
            $balanceKey = $wallet_scope === 'agency' ? 'balance' : 'credit_balance';

            if ($snapshot->exists()) {
                $data = $snapshot->data();
                $current_balance = (int)($data[$balanceKey] ?? 0);
                if (isset($data['currency'])) {
                    $currency = $data['currency'];
                }
            }

            $new_balance = $current_balance + $amount;

            $accountData = [
                $balanceKey => $new_balance,
                'updated_at' => $ts
            ];

            if (!$snapshot->exists()) {
                $accountData['created_at'] = $ts;
                if ($wallet_scope === 'subaccount') {
                    $accountData['currency'] = $currency;
                }
                $transaction->set($accountRef, $accountData);
            }
            else {
                $transaction->set($accountRef, $accountData, ['merge' => true]);
            }

            $transaction->create($transactionRef, [
                'transaction_id' => $transactionRef->id(),
                'account_id' => $accountRef->id(),
                'wallet_scope' => $wallet_scope,
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

    /**
     * Records a zero-balance transaction for free trial usage.
     * This provides visibility in the transaction history without affecting the paid balance.
     */
    public function record_trial_usage(string $account_id, int $amount, string $reference_id, string $description): bool
    {
        $accountRef = $this->get_account_ref($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts = new \Google\Cloud\Core\Timestamp($now);

        // Read current paid balance (trial usage does NOT change it)
        $currentBalance = 0;
        $snap = $accountRef->snapshot();
        if ($snap->exists()) {
            $currentBalance = (int)($snap->data()['credit_balance'] ?? 0);
        }

        // Simple batch to create the transaction record
        $batch = $this->db->batch();

        $batch->create($transactionRef, [
            'transaction_id' => $transactionRef->id(),
            'account_id' => $accountRef->id(),
            'wallet_scope' => 'subaccount',
            'type' => 'deduction',
            'amount' => 0, // No paid credit deduction
            'balance_after' => $currentBalance, // Paid balance unchanged
            'free_usage_applied' => $amount, // Tracks how many trial credits were used
            'reference_id' => $reference_id,
            'description' => $description,
            'created_at' => $ts
        ]);

        $batch->commit();

        return true;
    }


    /**
     * Helper to get the correct account reference based on account_id.
     * Uses 'integrations' collection with 'ghl_' prefix for GHL locations.
     */
    private function get_account_ref($account_id)
    {
        // Robust handling of $account_id: ensure it's a string, not an array
        if (is_array($account_id)) {
            // Log this as it suggests a bug elsewhere or weird webhook payload
            error_log("CreditManager: \$account_id is an array: " . print_r($account_id, true));
            $account_id = $account_id['id'] ?? $account_id['locationId'] ?? $account_id['location_id'] ?? $account_id[0] ?? 'default';
        }

        $account_id = trim((string)$account_id);

        if ($account_id === 'default' || empty($account_id)) {
            return $this->db->collection('accounts')->document('default');
        }

        // Ensure ghl_ prefix
        $docId = (strpos($account_id, 'ghl_') === 0)
            ? $account_id
            : 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $account_id);

        return $this->db->collection('integrations')->document($docId);
    }
    
    private function get_agency_ref($agency_id)
    {
        $agency_id = trim((string)$agency_id);
        return $this->db->collection('agency_wallet')->document($agency_id);
    }
}
