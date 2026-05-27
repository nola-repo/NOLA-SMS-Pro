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
     * Deducts ONLY from the subaccount wallet (users/{id}.credit_balance when linked by
     * active_location_id; otherwise legacy integrations/{ghl_*}.credit_balance).
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
            $agency_id, $location_id, $provider_cost, $charged, $profit, $provider, $ts, $metadata
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

            $subTxAccountId = self::integration_doc_id_for_location($location_id);

            $transactionPayload = array_merge([
                'transaction_id' => $transactionRef->id(),
                'account_id'     => $subTxAccountId,
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

        $this->invalidateSubaccountCache($location_id);

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
            $subTxAccountId = self::integration_doc_id_for_location($location_id);

            $transactionPayloadSub = array_merge([
                'transaction_id' => $transactionRefSub->id(),
                'account_id'     => $subTxAccountId,
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
                'target_account' => $subTxAccountId,
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
                'description'    => $description . " (via " . $subTxAccountId . ")",
                'created_at'     => $ts,
            ], $metadata);

            $transaction->create($transactionRefAgency, $transactionPayloadAgency);

            return ['success' => true, 'balance_after' => $new_sub_balance, 'agency_balance_after' => $new_agency_balance];
        });

        if (is_array($result) && !($result['success'] ?? false)) {
            throw new \Exception(json_encode($result));
        }

        $this->invalidateSubaccountCache($location_id);

        return $result;
    }

    /**
     * Returns whether the agency has enforce_master_balance_lock enabled.
     * When true, SMS sends are blocked if the agency wallet balance is 0.
     */
    public function get_agency_master_lock(string $agency_id): bool
    {
        if (empty($agency_id)) {
            return false;
        }
        $snap = $this->get_agency_ref($agency_id)->snapshot();
        if ($snap->exists()) {
            $data = $snap->data();
            if (array_key_exists('enforce_master_balance_lock', $data)) {
                return (bool)($data['enforce_master_balance_lock'] ?? false);
            }
        }
        $fallback = $this->db->collection('agency_wallet')->document(trim((string)$agency_id))->snapshot();
        return $fallback->exists() ? (bool)($fallback->data()['enforce_master_balance_lock'] ?? false) : false;
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

        $result = $this->db->runTransaction(function ($transaction) use ($agencyRef, $subaccountRef, $transactionRefAgency, $transactionRefSub, $amount, $reference_id, $description, $ts, $agency_id, $location_id) {
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
                'account_id' => $agency_id,
                'wallet_scope' => 'agency',
                'type' => 'deduction',
                'amount' => -$amount,
                'balance_after' => $new_agency_balance,
                'reference_id' => $reference_id,
                'description' => $description,
                'created_at' => $ts
            ]);

            $subTxAccountId = self::integration_doc_id_for_location($location_id);

            $transaction->create($transactionRefSub, [
                'transaction_id' => $transactionRefSub->id(),
                'account_id' => $subTxAccountId,
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

        $this->invalidateSubaccountCache($location_id);

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

        $txAccountId = self::integration_doc_id_for_location((string)$account_id);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $ts, $txAccountId) {
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
                'account_id' => $txAccountId,
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

        $this->invalidateSubaccountCache($account_id);

        return $result;
    }

    public function add_credits($account_id, $amount, $reference_id, $description, $type = 'top_up', $wallet_scope = 'subaccount')
    {
        if ($amount <= 0) {
            return true;
        }

        $accountRef = $wallet_scope === 'agency' ? $this->get_agency_ref($account_id) : $this->get_account_ref($account_id);
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $txAccountId = $wallet_scope === 'agency'
            ? trim((string)$account_id)
            : self::integration_doc_id_for_location((string)$account_id);

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $transactionRef, $amount, $reference_id, $description, $type, $wallet_scope, $ts, $txAccountId) {
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
                'account_id' => $txAccountId,
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

        if ($wallet_scope !== 'agency') {
            $this->invalidateSubaccountCache($account_id);
        }

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

        $txAccountId = self::integration_doc_id_for_location($account_id);

        // Simple batch to create the transaction record
        $batch = $this->db->batch();

        $batch->create($transactionRef, [
            'transaction_id' => $transactionRef->id(),
            'account_id' => $txAccountId,
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

        $this->invalidateSubaccountCache($account_id);

        return true;
    }


    /**
     * Canonical integrations-style id (e.g. ghl_xxx) stored in credit_transactions.account_id for subaccount rows.
     */
    public static function integration_doc_id_for_location(string $locationOrDocId): string
    {
        $id = trim($locationOrDocId);
        if ($id === '' || $id === 'default') {
            return 'default';
        }
        if (strpos($id, 'ghl_') === 0) {
            return $id;
        }

        return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id);
    }

    /**
     * Firestore document used for subaccount credit_balance reads/writes (users or legacy integrations).
     */
    public function resolveSubaccountBalanceDocument(string $location_id)
    {
        return $this->get_account_ref($location_id);
    }

    /**
     * Firestore document used for agency balance reads/writes (agency_users or legacy agency_wallet).
     */
    public function resolveAgencyBalanceDocument(string $agency_id)
    {
        return $this->get_agency_ref($agency_id);
    }

    /**
     * @return \Google\Cloud\Firestore\DocumentReference|null
     */
    private function find_user_ref_for_subaccount_wallet(string $locationKey)
    {
        foreach ($this->activeLocationIdQueryCandidates($locationKey) as $loc) {
            foreach (['active_location_id', 'location_id'] as $field) {
                $query = $this->db->collection('users')->where($field, '=', $loc)->limit(1)->documents();
                foreach ($query as $userDoc) {
                    if ($userDoc->exists()) {
                        $this->backfill_user_credit_balance_if_missing($userDoc->reference(), $locationKey);
                        return $userDoc->reference();
                    }
                }
            }
        }

        return null;
    }

    /**
     * Ensure users/{id}.credit_balance exists when a sub-account owner is linked (sync from legacy integrations).
     */
    public function ensureSubaccountCreditBalanceForLocation(string $locationId): void
    {
        $locationId = trim($locationId);
        if ($locationId === '') {
            return;
        }

        $userRef = $this->find_user_ref_for_subaccount_wallet($locationId);
        if ($userRef !== null) {
            $this->backfill_user_credit_balance_if_missing($userRef, $locationId);
        }
    }

    private function backfill_user_credit_balance_if_missing($userRef, string $locationKey): void
    {
        try {
            $userSnap = $userRef->snapshot();
            if (!$userSnap->exists()) {
                return;
            }

            $userData = $userSnap->data();
            if (array_key_exists('credit_balance', $userData)) {
                return;
            }

            $legacyBalance = 0;
            $legacyRef = $this->db->collection('integrations')->document(self::integration_doc_id_for_location($locationKey));
            $legacySnap = $legacyRef->snapshot();
            if ($legacySnap->exists()) {
                $legacyData = $legacySnap->data();
                if (array_key_exists('credit_balance', $legacyData) && is_numeric($legacyData['credit_balance'])) {
                    $legacyBalance = max(0, (int)$legacyData['credit_balance']);
                }
            }

            $userRef->set([
                'credit_balance' => $legacyBalance,
                'updated_at' => new Timestamp(new \DateTimeImmutable()),
            ], ['merge' => true]);
        } catch (\Throwable $e) {
            error_log('[CreditManager] credit_balance backfill skipped for ' . $locationKey . ': ' . $e->getMessage());
        }
    }

    /**
     * @return list<string>
     */
    private function activeLocationIdQueryCandidates(string $locationKey): array
    {
        $k = trim($locationKey);
        if ($k === '') {
            return [];
        }
        $out = [$k];
        if (strpos($k, 'ghl_') === 0) {
            $suffix = substr($k, 4);
            if ($suffix !== '') {
                $out[] = $suffix;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return \Google\Cloud\Firestore\DocumentReference|null
     */
    private function find_agency_user_ref(string $companyId)
    {
        $companyId = trim((string)$companyId);
        if ($companyId === '') {
            return null;
        }
        $query = $this->db->collection('agency_users')->where('company_id', '=', $companyId)->limit(1)->documents();
        foreach ($query as $doc) {
            if ($doc->exists()) {
                return $doc->reference();
            }
        }

        return null;
    }

    /**
     * Subaccount wallet: prefer users/{id} matched by active_location_id; fallback integrations/{ghl_...}.
     */
    private function get_account_ref($account_id)
    {
        if (is_array($account_id)) {
            error_log('CreditManager: $account_id is an array: ' . print_r($account_id, true));
            $account_id = $account_id['id'] ?? $account_id['locationId'] ?? $account_id['location_id'] ?? $account_id[0] ?? 'default';
        }

        $account_id = trim((string)$account_id);

        if ($account_id === 'default' || $account_id === '') {
            return $this->db->collection('accounts')->document('default');
        }

        $userRef = $this->find_user_ref_for_subaccount_wallet($account_id);
        if ($userRef !== null) {
            return $userRef;
        }

        $docId = self::integration_doc_id_for_location($account_id);

        return $this->db->collection('integrations')->document($docId);
    }

    /**
     * Agency wallet: prefer agency_users matched by company_id; fallback agency_wallet/{company_id}.
     */
    private function get_agency_ref($agency_id)
    {
        $agency_id = trim((string)$agency_id);
        $ref = $this->find_agency_user_ref($agency_id);
        if ($ref !== null) {
            return $ref;
        }

        return $this->db->collection('agency_wallet')->document($agency_id);
    }

    private function invalidateSubaccountCache(string $locId): void
    {
        try {
            $rawLocId = preg_replace('/^ghl_/', '', trim($locId));
            if ($rawLocId !== '' && $rawLocId !== 'default') {
                require_once __DIR__ . '/../cache_helper.php';
                NolaCache::deleteRegistry("credits_registry_" . $rawLocId);
            }
        } catch (\Throwable $e) {
            error_log("[CreditManager] Cache invalidation failed: " . $e->getMessage());
        }
    }
}
