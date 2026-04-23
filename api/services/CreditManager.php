<?php

require_once __DIR__ . '/../webhook/firestore_client.php';

use Google\Cloud\Core\Timestamp;

class CreditManager
{
    private $db;
    private $globalPricing = null;

    public function __construct()
    {
        $this->db = get_firestore();
    }

    public function get_global_pricing(): array
    {
        if ($this->globalPricing !== null) {
            return $this->globalPricing;
        }

        $docRef = $this->db->collection('admin_config')->document('global_pricing');
        $snapshot = $docRef->snapshot();
        
        if ($snapshot->exists()) {
            $data = $snapshot->data();
            $this->globalPricing = [
                'provider_cost' => (float)($data['provider_cost'] ?? 0.02),
                'charged'       => (float)($data['charged'] ?? 0.05)
            ];
        } else {
            $this->globalPricing = [
                'provider_cost' => 0.02,
                'charged'       => 0.05
            ];
        }

        return $this->globalPricing;
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

    /**
     * PRIMARY SMS DEDUCTION METHOD — Single-Deduction Architecture.
     *
     * Deducts ONLY from the subaccount wallet (integrations/{location_id}.credit_balance).
     * The agency wallet is NEVER touched here.
     *
     * Logs one credit_transactions entry with full profit-tracking metadata.
     *
     * @param string $location_id   Subaccount location ID (with or without ghl_ prefix)
     * @param string $agency_id     Agency ID (stored in log for reporting only)
     * @param int    $amount        Credits to deduct (usually 1 per SMS segment)
     * @param string $reference_id  Batch ID or reference string
     * @param string $description   Human-readable description (e.g. "SMS to +639...")  
     * @param float  $provider_cost Actual cost to us from the provider
     * @param float  $charged       What we bill the client per credit
     * @param string $provider      Provider name (e.g. 'telnyx', 'semaphore')
     * @return array ['success'=>true, 'balance_after'=>int] or throws on failure
     */
    public function deduct_subaccount_only(
        string $location_id,
        string $agency_id,
        int    $amount,
        string $reference_id,
        string $description,
        ?float $provider_cost = null,
        ?float $charged       = null,
        string $provider      = 'semaphore',
        array  $metadata      = []
    ): array {
        $pricing = $this->get_global_pricing();
        $provider_cost = $provider_cost !== null ? $provider_cost : $pricing['provider_cost'];
        $charged = $charged !== null ? $charged : $pricing['charged'];

        if ($amount <= 0) {
            return ['success' => true, 'balance_after' => $this->get_balance($location_id)];
        }

        $subaccountRef  = $this->get_account_ref($location_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts  = new Timestamp($now);

        $profit = round($charged - $provider_cost, 4);

        $result = $this->db->runTransaction(function ($transaction) use (
            $subaccountRef, $transactionRef, $amount, $reference_id, $description,
            $agency_id, $provider_cost, $charged, $profit, $provider, $ts, $metadata
        ) {
            $snap            = $transaction->snapshot($subaccountRef);
            $current_balance = $snap->exists() ? (int)($snap->data()['credit_balance'] ?? 0) : 0;

            if ($current_balance < $amount) {
                return [
                    'success'             => false,
                    'error'               => 'insufficient_credits',
                    'subaccount_balance'  => $current_balance,
                ];
            }

            $new_balance = $current_balance - $amount;

            $transaction->set($subaccountRef, [
                'credit_balance' => $new_balance,
                'updated_at'     => $ts,
            ], ['merge' => true]);

            $transactionPayload = array_merge([
                'transaction_id' => $transactionRef->id(),
                'account_id'     => $subaccountRef->id(),
                'agency_id'      => $agency_id,
                'wallet_scope'   => 'subaccount',
                'type'           => 'sms_usage',
                'deducted_from'  => 'subaccount',
                'amount'         => -$amount,
                'balance_after'  => $new_balance,
                'provider_cost'  => $provider_cost,
                'charged'        => $charged,
                'profit'         => $profit,
                'provider'       => $provider,
                'reference_id'   => $reference_id,
                'description'    => $description,
                'created_at'     => $ts,
            ], $metadata);

            $transaction->create($transactionRef, $transactionPayload);

            return ['success' => true, 'balance_after' => $new_balance];
        });

        if (is_array($result) && !($result['success'] ?? false)) {
            throw new \Exception(json_encode($result));
        }

        return $result;
    }

    /**
     * PRIMARY SMS DEDUCTION METHOD — Dual-Deduction Architecture.
     *
     * Deducts from BOTH agency and subaccount wallets atomically.
     * Generates two separate transaction logs (one for subaccount, one for agency).
     *
     * @param string $location_id        Subaccount location ID
     * @param string $agency_id          Agency ID
     * @param int    $subaccount_amount  Credits to deduct from subaccount
     * @param int    $agency_amount      Credits to deduct from agency
     * @param string $reference_id       Batch ID or reference string
     * @param string $description        Human-readable description
     * @param float  $provider_cost      Actual cost to us from the provider
     * @param float  $charged            What we bill the client per credit
     * @param string $provider           Provider name (e.g. 'telnyx', 'semaphore')
     * @return array ['success'=>true] or throws on failure
     */
    public function deduct_agency_and_subaccount(
        string $location_id,
        string $agency_id,
        int    $subaccount_amount,
        int    $agency_amount,
        string $reference_id,
        string $description,
        ?float $provider_cost = null,
        ?float $charged       = null,
        string $provider      = 'semaphore',
        array  $metadata      = []
    ): array {
        $pricing = $this->get_global_pricing();
        $provider_cost = $provider_cost !== null ? $provider_cost : $pricing['provider_cost'];
        $charged = $charged !== null ? $charged : $pricing['charged'];

        if ($subaccount_amount <= 0 && $agency_amount <= 0) {
            return ['success' => true, 'balance_after' => $this->get_balance($location_id)];
        }

        $subaccountRef  = $this->get_account_ref($location_id);
        $agencyRef      = $this->get_agency_ref($agency_id);
        
        $transactionRefSub    = $this->db->collection('credit_transactions')->newDocument();
        $transactionRefAgency = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts  = new Timestamp($now);

        $profit = round($charged - $provider_cost, 4);

        $result = $this->db->runTransaction(function ($transaction) use (
            $subaccountRef, $agencyRef, $transactionRefSub, $transactionRefAgency, 
            $subaccount_amount, $agency_amount, $reference_id, $description,
            $agency_id, $location_id, $provider_cost, $charged, $profit, $provider, $ts, $metadata
        ) {
            $snapSub    = $transaction->snapshot($subaccountRef);
            $snapAgency = $transaction->snapshot($agencyRef);

            $current_sub_balance    = $snapSub->exists() ? (int)($snapSub->data()['credit_balance'] ?? 0) : 0;
            $current_agency_balance = $snapAgency->exists() ? (int)($snapAgency->data()['balance'] ?? 0) : 0;

            if ($current_sub_balance < $subaccount_amount || $current_agency_balance < $agency_amount) {
                return [
                    'success'            => false,
                    'error'              => 'insufficient_credits',
                    'subaccount_balance' => $current_sub_balance,
                    'agency_balance'     => $current_agency_balance
                ];
            }

            $new_sub_balance    = $current_sub_balance - $subaccount_amount;
            $new_agency_balance = $current_agency_balance - $agency_amount;

            $transaction->set($subaccountRef, [
                'credit_balance' => $new_sub_balance,
                'updated_at'     => $ts,
            ], ['merge' => true]);

            $transaction->set($agencyRef, [
                'balance'    => $new_agency_balance,
                'updated_at' => $ts,
            ], ['merge' => true]);

            // Subaccount Transaction Log
            $transactionPayloadSub = array_merge([
                'transaction_id' => $transactionRefSub->id(),
                'account_id'     => $subaccountRef->id(),
                'agency_id'      => $agency_id,
                'wallet_scope'   => 'subaccount',
                'type'           => 'sms_usage',
                'deducted_from'  => 'subaccount',
                'amount'         => -$subaccount_amount,
                'balance_after'  => $new_sub_balance,
                'provider_cost'  => $provider_cost,
                'charged'        => $charged,
                'profit'         => $profit,
                'provider'       => $provider,
                'reference_id'   => $reference_id,
                'description'    => $description,
                'created_at'     => $ts,
            ], $metadata);

            $transaction->create($transactionRefSub, $transactionPayloadSub);

            // Agency Transaction Log
            $transactionPayloadAgency = array_merge([
                'transaction_id' => $transactionRefAgency->id(),
                'account_id'     => $agency_id,
                'target_account' => $subaccountRef->id(),
                'wallet_scope'   => 'agency',
                'type'           => 'agency_deduction',
                'deducted_from'  => 'agency',
                'amount'         => -$agency_amount,
                'balance_after'  => $new_agency_balance,
                'provider_cost'  => $provider_cost,
                'charged'        => $charged,
                'profit'         => $profit,
                'provider'       => $provider,
                'reference_id'   => $reference_id,
                'description'    => $description . " (via " . $subaccountRef->id() . ")",
                'created_at'     => $ts,
            ], $metadata);

            $transaction->create($transactionRefAgency, $transactionPayloadAgency);

            return ['success' => true, 'balance_after' => $new_sub_balance, 'agency_balance_after' => $new_agency_balance];
        });

        if (is_array($result) && !($result['success'] ?? false)) {
            throw new \Exception(json_encode($result));
        }

        return $result;
    }

    /**
     * Returns whether the agency has enforce_master_balance_lock enabled.
     * When true, SMS sends are blocked if the agency wallet balance is 0.
     */
    public function get_agency_master_lock(string $agency_id): bool
    {
        if (empty($agency_id)) return false;
        $snap = $this->get_agency_ref($agency_id)->snapshot();
        return $snap->exists() ? (bool)($snap->data()['enforce_master_balance_lock'] ?? false) : false;
    }

    /**
     * @deprecated Use deduct_subaccount_only() for SMS sends.
     * Kept for backward compatibility. Deducts from BOTH agency and subaccount wallets.
     */
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
                return [
                    'success' => false,
                    'error' => 'insufficient_credits',
                    'agency_balance' => $agency_balance,
                    'subaccount_balance' => $sub_balance
                ];
            }

            $new_agency_balance = $agency_balance - $amount;
            $new_sub_balance = $sub_balance - $amount;

            $transaction->set($agencyRef, [
                'balance' => $new_agency_balance,
                'updated_at' => $ts
            ], ['merge' => true]);

            $transaction->set($subaccountRef, [
                'credit_balance' => $new_sub_balance,
                'updated_at' => $ts
            ], ['merge' => true]);

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
