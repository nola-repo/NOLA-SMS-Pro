<?php

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/ReferenceId.php';

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

        if (preg_match('/<[a-zA-Z][^>]*>/', $message)) {
            $message = preg_replace('/<\/(p|div|br|li|tr|h[1-6])>/i', "\n", $message);
            $message = strip_tags($message);
            $message = html_entity_decode($message, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $message = trim(preg_replace('/[^\S\n]+/', ' ', $message));
        }

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
        $agency_id = trim((string)$agency_id);
        if ($agency_id === '') {
            return 0;
        }

        $docRef = $this->get_agency_ref($agency_id);
        $snapshot = $docRef->snapshot();
        $balance = 0;
        if ($snapshot->exists()) {
            $balance = (int)($snapshot->data()['balance'] ?? 0);
        }

        $legacySnapshot = $this->db->collection('agency_wallet')->document($agency_id)->snapshot();
        if ($legacySnapshot->exists()) {
            $legacyData = [];
            foreach ($legacySnapshot->data() as $key => $value) {
                $legacyData[trim((string)$key)] = $value;
            }
            if (isset($legacyData['balance']) && is_numeric($legacyData['balance'])) {
                $balance = max($balance, (int)$legacyData['balance']);
            }
        }

        return $balance;
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
     * @param string $provider      Provider name (e.g. 'telnyx', 'semaphore', 'unisms')
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

        $result = $this->runTransactionWithRetry(function ($transaction) use (
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
        // Also keep agency_wallet in sync for the Billing page Firestore listener.
        $agencyWalletRef = $this->db->collection('agency_wallet')->document(trim($agency_id));

        $transactionRefSub    = $this->db->collection('credit_transactions')->newDocument();
        $transactionRefAgency = $this->db->collection('credit_transactions')->newDocument();

        $now = new \DateTimeImmutable();
        $ts  = new Timestamp($now);

        $profit = round($charged - $provider_cost, 4);

        $result = $this->runTransactionWithRetry(function ($transaction) use (
            $subaccountRef, $agencyRef, $agencyWalletRef,
            $transactionRefSub, $transactionRefAgency,
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

            // Deduct from the primary agency document (agency_users)
            $transaction->set($agencyRef, [
                'balance'    => $new_agency_balance,
                'updated_at' => $ts,
            ], ['merge' => true]);

            // Mirror balance to agency_wallet so the Billing page real-time listener stays accurate
            $snapWallet = $transaction->snapshot($agencyWalletRef);
            if ($snapWallet->exists()) {
                $transaction->set($agencyWalletRef, [
                    'balance'    => $new_agency_balance,
                    'updated_at' => $ts,
                ], ['merge' => true]);
            }

            $transaction->set($subaccountRef, [
                'credit_balance' => $new_sub_balance,
                'updated_at'     => $ts,
            ], ['merge' => true]);

            // Subaccount Transaction Log
            $subTxAccountId = self::integration_doc_id_for_location($location_id);

            $transactionPayloadSub = array_merge([
                'transaction_id' => $transactionRefSub->id(),
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
        // For agency top-ups, also keep agency_wallet in sync for the Billing page listener.
        $agencyWalletRef = $wallet_scope === 'agency'
            ? $this->db->collection('agency_wallet')->document(trim((string)$account_id))
            : null;
        $transactionRef = $this->db->collection('credit_transactions')->newDocument();

        $txAccountId = $wallet_scope === 'agency'
            ? trim((string)$account_id)
            : self::integration_doc_id_for_location((string)$account_id);

        $now = new \DateTimeImmutable();
        $ts = new Timestamp($now);

        $result = $this->db->runTransaction(function ($transaction) use ($accountRef, $agencyWalletRef, $transactionRef, $amount, $reference_id, $description, $type, $wallet_scope, $ts, $txAccountId) {
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
                $balanceKey  => $new_balance,
                'updated_at' => $ts
            ];

            if (!$snapshot->exists()) {
                $accountData['created_at'] = $ts;
                if ($wallet_scope === 'subaccount') {
                    $accountData['currency'] = $currency;
                }
                $transaction->set($accountRef, $accountData);
            } else {
                $transaction->set($accountRef, $accountData, ['merge' => true]);
            }

            // Mirror balance to agency_wallet so the Billing page real-time listener stays accurate
            if ($agencyWalletRef !== null) {
                $snapWallet = $transaction->snapshot($agencyWalletRef);
                if ($snapWallet->exists()) {
                    $transaction->set($agencyWalletRef, [
                        'balance'    => $new_balance,
                        'updated_at' => $ts,
                    ], ['merge' => true]);
                }
            }

            $transaction->create($transactionRef, [
                'transaction_id' => $transactionRef->id(),
                'transaction_reference_id' => ReferenceId::generate('TXN'),
                'account_id'     => $txAccountId,
                'wallet_scope'   => $wallet_scope,
                'type'           => $type,
                'amount'         => $amount,
                'balance_after'  => $new_balance,
                'reference_id'   => $reference_id,
                'description'    => $description,
                'created_at'     => $ts
            ]);

            return $new_balance;
        });

        if ($wallet_scope !== 'agency') {
            $this->invalidateSubaccountCache($account_id);
        } else {
            try {
                require_once __DIR__ . '/../cache_helper.php';
                NolaCache::invalidateAgencyDashboard((string)$account_id);
            } catch (\Throwable $e) {
                error_log("[CreditManager] Agency cache invalidation failed: " . $e->getMessage());
            }
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
                'transaction_reference_id' => ReferenceId::generate('TXN'),
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
                $this->backfill_agency_balance_from_legacy($doc->reference(), $companyId);
                return $doc->reference();
            }
        }

        return null;
    }

    private function backfill_agency_balance_from_legacy($agencyRef, string $agencyId): void
    {
        try {
            // Only backfill if agency_users does NOT have its own 'balance' field yet.
            // Once a balance field exists it is managed by deductions/top-ups — NEVER overwrite it.
            $agencySnap = $agencyRef->snapshot();
            $agencyData = $agencySnap->exists() ? $agencySnap->data() : [];
            if (array_key_exists('balance', $agencyData)) {
                return; // Balance already managed here; do not reset from legacy agency_wallet.
            }

            $legacySnap = $this->db->collection('agency_wallet')->document($agencyId)->snapshot();
            if (!$legacySnap->exists()) {
                return;
            }

            $legacyData = [];
            foreach ($legacySnap->data() as $key => $value) {
                $legacyData[trim((string)$key)] = $value;
            }

            if (!array_key_exists('balance', $legacyData) || !is_numeric($legacyData['balance'])) {
                return;
            }

            $legacyBalance = max(0, (int)$legacyData['balance']);

            $agencyRef->set([
                'balance' => $legacyBalance,
                'updated_at' => new Timestamp(new \DateTimeImmutable()),
            ], ['merge' => true]);

            error_log('[CreditManager] Agency balance seeded from agency_wallet for ' . $agencyId . ': ' . $legacyBalance);
        } catch (\Throwable $e) {
            error_log('[CreditManager] agency balance backfill skipped for ' . $agencyId . ': ' . $e->getMessage());
        }
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

    /**
     * Idempotently refunds credits when the SMS provider connection timed out.
     *
     * Safe to call only for confirmed TCP/cURL connection timeouts — NOT for
     * delivery failures (provider accepted the message but later failed delivery).
     * A timeout means the provider never received the request, so no SMS was sent.
     *
     * @param string      $location_id          The subaccount location ID
     * @param int         $amount               Credits to refund (must match what was deducted)
     * @param string      $billing_reference_id The original billing reference ID for this send
     * @param string|null $agency_id            Agency ID if dual-wallet deduction was active
     * @param bool        $refund_agency_wallet Whether to also refund the agency wallet
     * @return array{refunded:bool, amount:int, reason?:string}
     */
    public function refundOnTimeout(
        string $location_id,
        int $amount,
        string $billing_reference_id,
        ?string $agency_id = null,
        bool $refund_agency_wallet = false
    ): array {
        if ($amount <= 0 || trim($location_id) === '' || trim($billing_reference_id) === '') {
            return ['refunded' => false, 'amount' => 0, 'reason' => 'nothing_to_refund'];
        }

        // Idempotency guard: check if we already issued a timeout refund for this reference
        $refundRef = 'timeout_refund_' . $billing_reference_id;
        try {
            $existing = $this->db->collection('credit_transactions')
                ->where('reference_id', '=', $refundRef)
                ->limit(1)
                ->documents();
            foreach ($existing as $doc) {
                if ($doc->exists() && ($doc->data()['type'] ?? '') === 'timeout_refund') {
                    return ['refunded' => false, 'amount' => 0, 'reason' => 'already_refunded'];
                }
            }
        } catch (\Throwable $e) {
            error_log('[CreditManager][refundOnTimeout] Idempotency check failed: ' . $e->getMessage());
        }

        try {
            $this->add_credits(
                $location_id,
                $amount,
                $refundRef,
                'Refund — SMS not sent (provider connection timed out)',
                'timeout_refund'
            );

            if ($refund_agency_wallet && $agency_id !== null && trim($agency_id) !== '') {
                $this->add_credits(
                    $agency_id,
                    $amount,
                    $refundRef . '_agency',
                    'Agency refund — SMS not sent (provider connection timed out)',
                    'timeout_refund',
                    'agency'
                );
            }

            error_log('[CreditManager][refundOnTimeout] Refunded ' . $amount . ' credits to loc=' . $location_id . ' ref=' . $billing_reference_id);
            return ['refunded' => true, 'amount' => $amount];
        } catch (\Throwable $e) {
            error_log('[CreditManager][refundOnTimeout] Refund failed for loc=' . $location_id . ': ' . $e->getMessage());
            return ['refunded' => false, 'amount' => 0, 'reason' => 'refund_exception: ' . $e->getMessage()];
        }
    }

    public function refundRetryTimeout(
        string $location_id,
        int $amount,
        string $billing_reference_id,
        ?string $agency_id = null,
        bool $refund_agency_wallet = false
    ): array {
        if ($amount <= 0 || trim($location_id) === '' || trim($billing_reference_id) === '') {
            return ['refunded' => false, 'amount' => 0, 'reason' => 'nothing_to_refund'];
        }

        $refundRef = 'timeout_refund_' . $billing_reference_id;
        $subaccountRef = $this->get_account_ref($location_id);
        $subTxRef = $this->db->collection('credit_transactions')->document(
            self::safeTransactionDocId('timeout_refund_', $billing_reference_id)
        );

        $agency_id = trim((string)($agency_id ?? ''));
        $agencyRef = null;
        $agencyWalletRef = null;
        $agencyTxRef = null;
        if ($refund_agency_wallet && $agency_id !== '') {
            $agencyRef = $this->get_agency_ref($agency_id);
            $agencyWalletRef = $this->db->collection('agency_wallet')->document($agency_id);
            $agencyTxRef = $this->db->collection('credit_transactions')->document(
                self::safeTransactionDocId('timeout_refund_', $billing_reference_id . '_agency')
            );
        }

        try {
            $result = $this->db->runTransaction(function ($transaction) use (
                $subaccountRef,
                $subTxRef,
                $agencyRef,
                $agencyWalletRef,
                $agencyTxRef,
                $amount,
                $refundRef,
                $location_id,
                $agency_id
            ) {
                $subTxSnap = $transaction->snapshot($subTxRef);
                if ($subTxSnap->exists()) {
                    return ['refunded' => false, 'amount' => 0, 'reason' => 'already_refunded'];
                }

                $now = new \DateTimeImmutable();
                $ts = new Timestamp($now);

                $subSnap = $transaction->snapshot($subaccountRef);
                $currentSubBalance = $subSnap->exists() ? (int)($subSnap->data()['credit_balance'] ?? 0) : 0;
                $newSubBalance = $currentSubBalance + $amount;

                $currentAgencyBalance = null;
                $newAgencyBalance = null;
                $walletSnap = null;
                if ($agencyRef !== null && $agencyTxRef !== null) {
                    $agencySnap = $transaction->snapshot($agencyRef);
                    $currentAgencyBalance = $agencySnap->exists() ? (int)($agencySnap->data()['balance'] ?? 0) : 0;
                    $newAgencyBalance = $currentAgencyBalance + $amount;
                    if ($agencyWalletRef !== null) {
                        $walletSnap = $transaction->snapshot($agencyWalletRef);
                    }
                }

                $transaction->set($subaccountRef, [
                    'credit_balance' => $newSubBalance,
                    'updated_at' => $ts,
                ], ['merge' => true]);

                $transaction->create($subTxRef, [
                    'transaction_id' => $subTxRef->id(),
                    'transaction_reference_id' => ReferenceId::generate('TXN'),
                    'account_id' => self::integration_doc_id_for_location($location_id),
                    'wallet_scope' => 'subaccount',
                    'type' => 'timeout_refund',
                    'amount' => $amount,
                    'balance_after' => $newSubBalance,
                    'reference_id' => $refundRef,
                    'description' => 'Refund - SMS not confirmed after provider timeout retries',
                    'created_at' => $ts,
                ]);

                if ($agencyRef !== null && $agencyTxRef !== null) {
                    $transaction->set($agencyRef, [
                        'balance' => $newAgencyBalance,
                        'updated_at' => $ts,
                    ], ['merge' => true]);

                    if ($agencyWalletRef !== null && $walletSnap !== null && $walletSnap->exists()) {
                        $transaction->set($agencyWalletRef, [
                            'balance' => $newAgencyBalance,
                            'updated_at' => $ts,
                        ], ['merge' => true]);
                    }

                    $transaction->create($agencyTxRef, [
                        'transaction_id' => $agencyTxRef->id(),
                        'transaction_reference_id' => ReferenceId::generate('TXN'),
                        'account_id' => $agency_id,
                        'target_account' => self::integration_doc_id_for_location($location_id),
                        'wallet_scope' => 'agency',
                        'type' => 'timeout_refund',
                        'amount' => $amount,
                        'balance_after' => $newAgencyBalance,
                        'reference_id' => $refundRef . '_agency',
                        'description' => 'Agency refund - SMS not confirmed after provider timeout retries',
                        'created_at' => $ts,
                    ]);
                }

                return ['refunded' => true, 'amount' => $amount];
            });

            $this->invalidateSubaccountCache($location_id);
            if ($refund_agency_wallet && $agency_id !== '') {
                try {
                    require_once __DIR__ . '/../cache_helper.php';
                    NolaCache::invalidateAgencyDashboard($agency_id);
                } catch (\Throwable $e) {}
            }
            return $result;
        } catch (\Throwable $e) {
            error_log('[CreditManager][refundRetryTimeout] Refund failed for loc=' . $location_id . ': ' . $e->getMessage());
            return ['refunded' => false, 'amount' => 0, 'reason' => 'refund_exception: ' . $e->getMessage()];
        }
    }

    public function refundTrialUsageOnTimeout(string $location_id, int $amount, string $billing_reference_id): array
    {
        if ($amount <= 0 || trim($location_id) === '' || trim($billing_reference_id) === '') {
            return ['refunded' => false, 'amount' => 0, 'reason' => 'nothing_to_refund'];
        }

        $integrationRef = $this->db->collection('integrations')->document(self::integration_doc_id_for_location($location_id));
        $txRef = $this->db->collection('credit_transactions')->document(
            self::safeTransactionDocId('timeout_trial_refund_', $billing_reference_id)
        );
        $refundRef = 'timeout_trial_refund_' . $billing_reference_id;

        try {
            $result = $this->db->runTransaction(function ($transaction) use ($integrationRef, $txRef, $amount, $refundRef, $location_id) {
                $txSnap = $transaction->snapshot($txRef);
                if ($txSnap->exists()) {
                    return ['refunded' => false, 'amount' => 0, 'reason' => 'already_refunded'];
                }

                $now = new \DateTimeImmutable();
                $ts = new Timestamp($now);
                $intSnap = $transaction->snapshot($integrationRef);
                $currentUsage = $intSnap->exists() ? (int)($intSnap->data()['free_usage_count'] ?? 0) : 0;
                $newUsage = max(0, $currentUsage - $amount);

                $transaction->set($integrationRef, [
                    'free_usage_count' => $newUsage,
                    'updated_at' => $ts,
                ], ['merge' => true]);

                $transaction->create($txRef, [
                    'transaction_id' => $txRef->id(),
                    'transaction_reference_id' => ReferenceId::generate('TXN'),
                    'account_id' => self::integration_doc_id_for_location($location_id),
                    'wallet_scope' => 'trial',
                    'type' => 'timeout_trial_refund',
                    'amount' => $amount,
                    'reference_id' => $refundRef,
                    'description' => 'Trial usage restored - SMS not confirmed after provider timeout retries',
                    'created_at' => $ts,
                ]);

                return ['refunded' => true, 'amount' => $amount];
            });

            $this->invalidateSubaccountCache($location_id);
            return $result;
        } catch (\Throwable $e) {
            error_log('[CreditManager][refundTrialUsageOnTimeout] Refund failed for loc=' . $location_id . ': ' . $e->getMessage());
            return ['refunded' => false, 'amount' => 0, 'reason' => 'refund_exception: ' . $e->getMessage()];
        }
    }

    private static function safeTransactionDocId(string $prefix, string $reference): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $reference);
        if (strlen($safe) > 90) {
            $safe = substr($safe, 0, 48) . '_' . substr(hash('sha256', $reference), 0, 32);
        }
        return $prefix . $safe;
    }

    private function invalidateSubaccountCache(string $locId): void
    {
        try {
            $rawLocId = preg_replace('/^ghl_/', '', trim($locId));
            if ($rawLocId !== '' && $rawLocId !== 'default') {
                require_once __DIR__ . '/../cache_helper.php';
                NolaCache::deleteRegistry("credits_registry_" . $rawLocId);

                $agencySnap = $this->db->collection('agency_subaccounts')->document($rawLocId)->snapshot();
                if ($agencySnap->exists()) {
                    $agencyId = trim((string)($agencySnap->data()['agency_id'] ?? ''));
                    if ($agencyId !== '') {
                        NolaCache::invalidateAgencyDashboard($agencyId);
                    }
                }

                // Best-effort sync updated credit balance to central GHL contact
                try {
                    $newBal = $this->get_balance($rawLocId);
                    require_once __DIR__ . '/NotificationService.php';
                    NotificationService::syncCentralBalanceContact($this->db, $rawLocId, $newBal);
                } catch (\Throwable $ignored) {
                }
            }
        } catch (\Throwable $e) {
            error_log("[CreditManager] Cache invalidation failed: " . $e->getMessage());
        }
    }

    private function runTransactionWithRetry(callable $callback)
    {
        $maxAttempts = 5;
        $attempt = 1;
        while (true) {
            try {
                return $this->db->runTransaction($callback);
            } catch (\Throwable $e) {
                $isContention = false;
                if ($e->getCode() === 10) {
                    $isContention = true;
                } elseif (str_contains($e->getMessage(), 'contention') || str_contains($e->getMessage(), 'ABORTED')) {
                    $isContention = true;
                }
                
                if ($isContention && $attempt < $maxAttempts) {
                    error_log("[CreditManager] Transaction contention on attempt {$attempt}, retrying in background. Error: " . $e->getMessage());
                    // Exponential backoff with jitter: sleep between 10ms and (2^attempt * 50)ms
                    $minDelay = 10000; // 10ms
                    $maxDelay = (1 << $attempt) * 50000; // e.g. 100ms, 200ms, 400ms, 800ms
                    usleep(random_int($minDelay, $maxDelay));
                    $attempt++;
                    continue;
                }
                throw $e;
            }
        }
    }
}
