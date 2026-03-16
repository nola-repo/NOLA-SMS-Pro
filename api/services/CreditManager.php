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
            } elseif (mb_strpos($gsm7_extension, $char, 0, 'UTF-8') !== false) {
                $gsm7_length += 2; // Extended characters count as 2
            } else {
                $is_unicode = true;
                break;
            }
        }

        if ($is_unicode) {
            // Unicode limits
            if ($len <= 70) {
                $segments = 1;
            } else {
                $segments = ceil($len / 67);
            }
        } else {
            // GSM-7 limits
            if ($gsm7_length <= 160) {
                $segments = 1;
            } else {
                $segments = ceil($gsm7_length / 153);
            }
        }

        return (int)max(1, $segments) * $num_recipients;
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
