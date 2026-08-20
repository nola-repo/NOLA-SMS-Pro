<?php

/**
 * retry_sms_queue.php - SMS Retry Queue Worker
 *
 * Cloud Scheduler should call this endpoint every 5 minutes with X-Cron-Secret.
 * The worker claims each due queue document before sending, so overlapping
 * scheduler invocations cannot process the same SMS at the same time.
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(55);

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../services/CreditManager.php';
require_once __DIR__ . '/../services/SmsGatewayService.php';
require_once __DIR__ . '/../services/MessageSyncService.php';
require_once __DIR__ . '/../services/GhlSyncService.php';
require_once __DIR__ . '/../services/providers/SmsProviderInterface.php';

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $cronSecret = getenv('CRON_SECRET');
    $providedSecret = $_SERVER['HTTP_X_CRON_SECRET'] ?? $_GET['cron_secret'] ?? null;
    if (empty($cronSecret) || $providedSecret !== $cronSecret) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Unauthorized: invalid or missing cron secret.']);
        exit;
    }
}

$db = get_firestore();
$startTime = microtime(true);
$workerId = 'retry_worker_' . getmypid() . '_' . bin2hex(random_bytes(3));
$processed = 0;
$claimed = 0;
$succeeded = 0;
$retried = 0;
$exhausted = 0;
$skipped = 0;
$errors = 0;

define('RETRY_BATCH_LIMIT', 20);
define('RETRY_INTERVAL_MIN', 5);
define('RETRY_MAX_ATTEMPTS', 3);
define('RETRY_LEASE_SECONDS', 240);

function retry_ts($value): ?int
{
    if ($value instanceof \Google\Cloud\Core\Timestamp) {
        return $value->get()->getTimestamp();
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->getTimestamp();
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    if (is_string($value) && trim($value) !== '') {
        $parsed = strtotime($value);
        return $parsed === false ? null : $parsed;
    }
    return null;
}

function retry_message_event($db, array $data, array $overrides): void
{
    $nowTs = new \Google\Cloud\Core\Timestamp(new \DateTime());
    MessageSyncService::recordMessageEvent($db, array_merge([
        'location_id' => (string)($data['location_id'] ?? ''),
        'number' => (string)($data['phone'] ?? ''),
        'message' => (string)($data['message'] ?? ''),
        'direction' => 'outbound',
        'sender_id' => (string)($data['sender_id'] ?? ''),
        'sender_name' => (string)($data['sender_id'] ?? ''),
        'ghl_message_id' => $data['ghl_message_id'] ?? null,
        'source' => 'ghl_provider',
        'updated_at' => $nowTs,
        'message_id' => (string)($data['message_id'] ?? ''),
    ], $overrides));
}

function claim_retry_doc($db, $docRef, string $workerId): ?array
{
    $now = time();
    $nowTs = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable('@' . $now));
    $leaseTs = new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable('@' . ($now + RETRY_LEASE_SECONDS)));

    return $db->runTransaction(function ($transaction) use ($docRef, $workerId, $now, $nowTs, $leaseTs) {
        $snap = $transaction->snapshot($docRef);
        if (!$snap->exists()) {
            return null;
        }

        $data = $snap->data();
        $status = strtolower(trim((string)($data['status'] ?? '')));
        $nextRetryAt = retry_ts($data['next_retry_at'] ?? null) ?? 0;
        $leaseExpiresAt = retry_ts($data['lease_expires_at'] ?? null) ?? 0;
        $claimable = ($status === 'pending_retry' && $nextRetryAt <= $now)
            || ($status === 'processing' && $leaseExpiresAt <= $now);

        if (!$claimable) {
            return null;
        }

        $attempts = (int)($data['attempts'] ?? 0);
        $maxAttempts = (int)($data['max_attempts'] ?? RETRY_MAX_ATTEMPTS);
        if ($attempts >= $maxAttempts) {
            return null;
        }

        $transaction->update($docRef, [
            ['path' => 'status', 'value' => 'processing'],
            ['path' => 'worker_id', 'value' => $workerId],
            ['path' => 'processing_started_at', 'value' => $nowTs],
            ['path' => 'lease_expires_at', 'value' => $leaseTs],
            ['path' => 'updated_at', 'value' => $nowTs],
        ]);

        $data['status'] = 'processing';
        $data['worker_id'] = $workerId;
        return $data;
    });
}

function update_queue_doc($docRef, array $updates): void
{
    $payload = [];
    foreach ($updates as $path => $value) {
        $payload[] = ['path' => $path, 'value' => $value];
    }
    $docRef->update($payload);
}

function process_retry_doc($db, $docRef, array $data, string $workerId): string
{
    $attempts = (int)($data['attempts'] ?? 0);
    $newAttempts = $attempts + 1;
    $maxAttempts = (int)($data['max_attempts'] ?? RETRY_MAX_ATTEMPTS);
    $locationId = (string)($data['location_id'] ?? '');
    $phone = (string)($data['phone'] ?? '');
    $message = (string)($data['message'] ?? '');
    $senderId = (string)($data['sender_id'] ?? '');
    $apiKey = $data['api_key'] ?? null;
    $providerPref = $data['provider_pref'] ?? null;
    $ghlMessageId = $data['ghl_message_id'] ?? null;

    error_log('[retry_sms_queue][ATTEMPT] ' . json_encode([
        'retry_doc_id' => $docRef->id(),
        'message_id' => $data['message_id'] ?? null,
        'location_id' => $locationId,
        'attempt_num' => $newAttempts,
        'max_attempts' => $maxAttempts,
        'worker_id' => $workerId,
    ]));

    try {
        retry_message_event($db, $data, [
            'origin' => 'retry_worker_processing',
            'status' => 'Pending',
            'retry_doc_id' => $docRef->id(),
            'retry_status' => 'processing',
            'retry_count' => $attempts,
            'retry_max_attempts' => $maxAttempts,
            'suppress_provider_reference' => true,
        ]);
    } catch (\Throwable $e) {
        error_log('[retry_sms_queue][PROCESSING_MSG_UPDATE_FAIL] ' . $e->getMessage());
    }

    try {
        $gateway = new SmsGatewayService();
        $res = $gateway->send([$phone], $message, $senderId, $apiKey ?: null, $providerPref ?: null);
        $results = $res['results'] ?? [];
        $firstRes = $results[0] ?? [];
        $rawStatus = strtolower((string)($firstRes['status'] ?? ''));
        $isHardFail = in_array($rawStatus, ['failed', 'rejected', 'undelivered', 'expired'], true);

        if (empty($firstRes['message_id']) || $isHardFail) {
            $reason = $firstRes['error'] ?? ('Provider returned failure status: ' . ($firstRes['status'] ?? 'unknown'));
            finalize_retry_exhausted($db, $docRef, $data, $newAttempts, $reason);
            return 'exhausted';
        }

        $nowTs = new \Google\Cloud\Core\Timestamp(new \DateTime());
        update_queue_doc($docRef, [
            'status' => 'completed',
            'attempts' => $newAttempts,
            'completed_at' => $nowTs,
            'last_error' => null,
            'worker_id' => null,
            'lease_expires_at' => null,
            'updated_at' => $nowTs,
            'final_provider' => $res['provider'] ?? 'semaphore',
            'final_provider_message_id' => $firstRes['provider_message_id'] ?? ($firstRes['message_id'] ?? null),
        ]);

        retry_message_event($db, $data, [
            'origin' => 'retry_worker_success',
            'status' => 'Sent',
            'provider' => $res['provider'] ?? 'semaphore',
            'provider_reference_id' => $firstRes['provider_reference_id'] ?? ($firstRes['message_id'] ?? null),
            'provider_message_id' => $firstRes['provider_message_id'] ?? ($firstRes['message_id'] ?? null),
            'provider_status' => $firstRes['status'] ?? null,
            'provider_response' => $firstRes['provider_response'] ?? null,
            'retry_doc_id' => $docRef->id(),
            'retry_status' => 'completed',
            'retry_count' => $newAttempts,
            'retry_max_attempts' => $maxAttempts,
        ]);

        if ($ghlMessageId && $locationId) {
            try {
                $ghlSync = new \Nola\Services\GhlSyncService($db, $locationId);
                $ghlSync->syncMessageStatus($ghlMessageId, 'Sent');
            } catch (\Throwable $e) {
                error_log('[retry_sms_queue][GHL_SYNC_FAIL] ' . $e->getMessage());
            }
        }

        return 'succeeded';
    } catch (SmsProviderTimeoutException $e) {
        if ($newAttempts >= $maxAttempts) {
            finalize_retry_exhausted($db, $docRef, $data, $newAttempts, $e->getMessage());
            return 'exhausted';
        }

        $nowTs = new \Google\Cloud\Core\Timestamp(new \DateTime());
        $nextRetry = (new \DateTime())->modify('+' . RETRY_INTERVAL_MIN . ' minutes');
        $nextRetryTs = new \Google\Cloud\Core\Timestamp($nextRetry);
        update_queue_doc($docRef, [
            'status' => 'pending_retry',
            'attempts' => $newAttempts,
            'next_retry_at' => $nextRetryTs,
            'last_error' => $e->getMessage(),
            'worker_id' => null,
            'lease_expires_at' => null,
            'updated_at' => $nowTs,
        ]);

        try {
            retry_message_event($db, $data, [
                'origin' => 'ghl_provider_retry_queued',
                'status' => 'Pending',
                'provider' => $e->provider ?: ($data['provider'] ?? null),
                'provider_error' => $e->getMessage(),
                'retry_doc_id' => $docRef->id(),
                'retry_status' => 'pending_retry',
                'retry_count' => $newAttempts,
                'retry_max_attempts' => $maxAttempts,
                'next_retry_at' => $nextRetryTs,
                'last_retry_at' => $nowTs,
                'suppress_provider_reference' => true,
            ]);
        } catch (\Throwable $updateEx) {
            error_log('[retry_sms_queue][RETRY_MSG_UPDATE_FAIL] ' . $updateEx->getMessage());
        }

        return 'retried';
    } catch (\Throwable $e) {
        finalize_retry_exhausted($db, $docRef, $data, $newAttempts, $e->getMessage());
        return 'exhausted';
    }
}

function finalize_retry_exhausted($db, $docRef, array $data, int $finalAttempts, string $reason): void
{
    $nowTs = new \Google\Cloud\Core\Timestamp(new \DateTime());
    update_queue_doc($docRef, [
        'status' => 'exhausted',
        'attempts' => $finalAttempts,
        'exhausted_at' => $nowTs,
        'last_error' => $reason,
        'worker_id' => null,
        'lease_expires_at' => null,
        'updated_at' => $nowTs,
    ]);

    try {
        retry_message_event($db, $data, [
            'origin' => 'retry_worker_exhausted',
            'status' => 'Failed',
            'provider' => $data['provider'] ?? 'semaphore',
            'provider_error' => $reason,
            'retry_doc_id' => $docRef->id(),
            'retry_status' => 'exhausted',
            'retry_count' => $finalAttempts,
            'retry_max_attempts' => (int)($data['max_attempts'] ?? RETRY_MAX_ATTEMPTS),
            'suppress_provider_reference' => true,
        ]);
    } catch (\Throwable $e) {
        error_log('[retry_sms_queue][FAILED_MSG_UPDATE_ERR] ' . $e->getMessage());
    }

    $ghlMessageId = $data['ghl_message_id'] ?? null;
    $locationId = (string)($data['location_id'] ?? '');
    if ($ghlMessageId && $locationId) {
        try {
            $ghlSync = new \Nola\Services\GhlSyncService($db, $locationId);
            $ghlSync->syncMessageStatus($ghlMessageId, 'Failed');
        } catch (\Throwable $e) {
            error_log('[retry_sms_queue][GHL_FAIL_SYNC_ERR] ' . $e->getMessage());
        }
    }

    $billingCharged = (bool)($data['billing_charged'] ?? true);
    $requiredCredits = (int)($data['required_credits'] ?? 0);
    if (!$billingCharged || $requiredCredits <= 0) {
        return;
    }

    try {
        $creditManager = new CreditManager();
        $billingRef = (string)($data['billing_reference_id'] ?? $docRef->id());
        if (!empty($data['using_free_credits'])) {
            $creditManager->refundTrialUsageOnTimeout($locationId, $requiredCredits, $billingRef);
        } else {
            $creditManager->refundRetryTimeout(
                $locationId,
                $requiredCredits,
                $billingRef,
                (string)($data['agency_id'] ?? ''),
                (bool)($data['billing_master_lock'] ?? false)
            );
        }
    } catch (\Throwable $e) {
        error_log('[retry_sms_queue][CREDIT_REFUND_FAIL] ' . $e->getMessage());
    }
}

function load_due_retry_documents($db): array
{
    $pendingDocs = $db->collection('sms_retry_queue')
        ->where('status', '=', 'pending_retry')
        ->orderBy('next_retry_at', 'asc')
        ->limit(RETRY_BATCH_LIMIT)
        ->documents();

    $expiredDocs = $db->collection('sms_retry_queue')
        ->where('status', '=', 'processing')
        ->orderBy('lease_expires_at', 'asc')
        ->limit(RETRY_BATCH_LIMIT)
        ->documents();

    $docs = [];
    foreach ([$pendingDocs, $expiredDocs] as $set) {
        foreach ($set as $doc) {
            $docs[$doc->id()] = $doc;
        }
    }
    return array_values($docs);
}

error_log('[retry_sms_queue] Worker started ' . json_encode(['worker_id' => $workerId]));

try {
    foreach (load_due_retry_documents($db) as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        if ((microtime(true) - $startTime) > 50) {
            break;
        }

        $processed++;
        $docRef = $doc->reference();
        $data = claim_retry_doc($db, $docRef, $workerId);
        if ($data === null) {
            $skipped++;
            continue;
        }

        $claimed++;
        $outcome = process_retry_doc($db, $docRef, $data, $workerId);
        if ($outcome === 'succeeded') {
            $succeeded++;
        } elseif ($outcome === 'retried') {
            $retried++;
        } elseif ($outcome === 'exhausted') {
            $exhausted++;
        }
    }
} catch (\Throwable $fatal) {
    $errors++;
    error_log('[retry_sms_queue][FATAL] ' . $fatal->getMessage());
}

$elapsed = round((microtime(true) - $startTime) * 1000, 1);
$summary = [
    'status' => 'ok',
    'worker_id' => $workerId,
    'processed' => $processed,
    'claimed' => $claimed,
    'succeeded' => $succeeded,
    'retried' => $retried,
    'exhausted' => $exhausted,
    'skipped' => $skipped,
    'errors' => $errors,
    'elapsed_ms' => $elapsed,
];

error_log('[retry_sms_queue] Done ' . json_encode($summary));
echo json_encode($summary);
