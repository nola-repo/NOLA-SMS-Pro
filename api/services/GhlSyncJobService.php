<?php

namespace Nola\Services;

require_once __DIR__ . '/GhlSyncService.php';
require_once __DIR__ . '/GhlClient.php';

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\FieldValue;

class GhlSyncJobService
{
    private const COLLECTION = 'ghl_sync_jobs';
    private const MAX_ATTEMPTS = 5;

    public function __construct(private $db)
    {
    }

    public function enqueueOutboundMessage(array $job): array
    {
        $messageId = trim((string)($job['message_id'] ?? ''));
        $locationId = trim((string)($job['location_id'] ?? ''));
        if ($messageId === '' || $locationId === '') {
            throw new \InvalidArgumentException('GHL sync job requires message_id and location_id.');
        }

        $now = new Timestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $jobId = self::jobId($messageId);
        $ref = $this->db->collection(self::COLLECTION)->document($jobId);

        $this->db->runTransaction(function ($transaction) use ($ref, $job, $now, $jobId) {
            $snap = $transaction->snapshot($ref);
            if ($snap->exists()) {
                $status = (string)($snap->data()['status'] ?? '');
                if (in_array($status, ['pending', 'processing', 'completed'], true)) {
                    return;
                }
            }

            $transaction->set($ref, array_filter([
                'job_id' => $jobId,
                'type' => 'outbound_message',
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => self::MAX_ATTEMPTS,
                'location_id' => $job['location_id'] ?? null,
                'token_registry_id' => $job['token_registry_id'] ?? null,
                'message_id' => $job['message_id'] ?? null,
                'phone' => $job['phone'] ?? null,
                'message' => $job['message'] ?? null,
                'contact_id' => $job['contact_id'] ?? null,
                'tags' => is_array($job['tags'] ?? null) ? array_values($job['tags']) : [],
                'created_at' => $now,
                'updated_at' => $now,
                'available_at' => $now,
                'expires_at' => new Timestamp(new \DateTimeImmutable('+7 days', new \DateTimeZone('UTC'))),
            ], static fn($value) => $value !== null), ['merge' => true]);
        });

        return ['success' => true, 'job_id' => $jobId, 'status' => 'queued'];
    }

    public function processDueJobs(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $results = [];
        $seen = [];

        foreach (['pending', 'processing'] as $status) {
            if (count($results) >= $limit) {
                break;
            }

            $remaining = $limit - count($results);
            $docs = $this->db->collection(self::COLLECTION)
                ->where('status', '=', $status)
                ->limit($remaining)
                ->documents();

            foreach ($docs as $doc) {
                if (!$doc->exists() || isset($seen[$doc->id()])) {
                    continue;
                }
                $seen[$doc->id()] = true;

                $claimed = $this->claimJob($doc->reference());
                if ($claimed === null) {
                    continue;
                }

                $results[] = $this->processClaimedJob($doc->reference(), $claimed);
                if (count($results) >= $limit) {
                    break;
                }
            }
        }

        return [
            'processed' => count($results),
            'results' => $results,
        ];
    }

    private function claimJob($ref): ?array
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $nowTs = new Timestamp($now);
        $leaseTs = new Timestamp($now->modify('+2 minutes'));

        return $this->db->runTransaction(function ($transaction) use ($ref, $now, $nowTs, $leaseTs) {
            $snap = $transaction->snapshot($ref);
            if (!$snap->exists()) {
                return null;
            }

            $data = $snap->data();
            $status = (string)($data['status'] ?? '');
            $availableAt = self::timestampValue($data['available_at'] ?? null);
            $leaseUntil = self::timestampValue($data['lease_until'] ?? null);

            if ($status === 'pending' && $availableAt !== null && $availableAt > $now->getTimestamp()) {
                return null;
            }
            if ($status === 'processing' && ($leaseUntil === null || $leaseUntil > $now->getTimestamp())) {
                return null;
            }
            if (!in_array($status, ['pending', 'processing'], true)) {
                return null;
            }

            $attempts = (int)($data['attempts'] ?? 0);
            if ($attempts >= (int)($data['max_attempts'] ?? self::MAX_ATTEMPTS)) {
                $transaction->set($ref, [
                    'status' => 'failed',
                    'updated_at' => $nowTs,
                    'last_error' => 'max_attempts_exceeded',
                ], ['merge' => true]);
                return null;
            }

            $transaction->set($ref, [
                'status' => 'processing',
                'attempts' => FieldValue::increment(1),
                'lease_until' => $leaseTs,
                'updated_at' => $nowTs,
            ], ['merge' => true]);

            $data['attempts'] = $attempts + 1;
            return $data;
        });
    }

    private function processClaimedJob($ref, array $job): array
    {
        $jobId = $ref->id();
        $messageId = (string)($job['message_id'] ?? '');
        $locationId = (string)($job['location_id'] ?? '');
        $tokenRegistryId = isset($job['token_registry_id']) ? (string)$job['token_registry_id'] : null;
        $now = new Timestamp(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        try {
            if (($job['type'] ?? '') !== 'outbound_message') {
                throw new \RuntimeException('Unsupported GHL sync job type.');
            }

            $ghlSync = new GhlSyncService($this->db, $locationId, $tokenRegistryId);
            $syncRes = $ghlSync->syncOutboundMessage(
                (string)($job['phone'] ?? ''),
                (string)($job['message'] ?? ''),
                isset($job['contact_id']) ? (string)$job['contact_id'] : null
            );

            $syncUpdate = array_filter([
                'ghl_sync_success' => (bool)($syncRes['success'] ?? false),
                'ghl_sync_skipped' => (bool)($syncRes['skipped'] ?? false),
                'ghl_sync_reason' => $syncRes['reason'] ?? null,
                'ghl_sync_error' => $syncRes['error'] ?? null,
                'ghl_sync_http_status' => $syncRes['ghl_response']['status'] ?? null,
                'ghl_sync_updated_at' => $now,
                'ghl_sync_job_id' => $jobId,
            ], static fn($value) => $value !== null);

            if (!empty($syncRes['ghl_message_id'])) {
                $syncUpdate['ghl_message_id'] = $syncRes['ghl_message_id'];
            }

            if ($messageId !== '') {
                $this->db->collection('messages')->document($messageId)->set($syncUpdate, ['merge' => true]);
                $this->db->collection('sms_logs')->document($messageId)->set($syncUpdate, ['merge' => true]);
            }

            $tagResult = $this->applyTags($job);
            $completed = !empty($syncRes['success']) || !empty($syncRes['skipped']);
            if ($completed) {
                $ref->set(array_filter([
                    'status' => 'completed',
                    'completed_at' => $now,
                    'updated_at' => $now,
                    'lease_until' => null,
                    'sync_result' => $syncRes,
                    'tag_result' => $tagResult,
                ], static fn($value) => $value !== null), ['merge' => true]);

                return ['job_id' => $jobId, 'status' => 'completed'];
            }

            throw new \RuntimeException((string)($syncRes['error'] ?? 'GHL sync failed.'));
        } catch (\Throwable $e) {
            $this->rescheduleOrFail($ref, $job, $e->getMessage());
            return ['job_id' => $jobId, 'status' => 'retry_or_failed', 'error' => $e->getMessage()];
        }
    }

    private function applyTags(array $job): array
    {
        $tags = $job['tags'] ?? [];
        $contactId = trim((string)($job['contact_id'] ?? ''));
        if (!is_array($tags) || empty($tags) || $contactId === '') {
            return ['success' => true, 'skipped' => true, 'reason' => 'no_tags_or_contact'];
        }

        $client = new \GhlClient(
            $this->db,
            (string)$job['location_id'],
            isset($job['token_registry_id']) ? (string)$job['token_registry_id'] : null
        );
        return $client->request(
            'POST',
            "/contacts/{$contactId}/tags",
            json_encode(['tags' => array_values($tags)]),
            '2021-07-28'
        );
    }

    private function rescheduleOrFail($ref, array $job, string $error): void
    {
        $attempts = (int)($job['attempts'] ?? 1);
        $maxAttempts = (int)($job['max_attempts'] ?? self::MAX_ATTEMPTS);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $failed = $attempts >= $maxAttempts;
        $delaySeconds = min(3600, max(30, 30 * (2 ** max(0, $attempts - 1))));

        $ref->set(array_filter([
            'status' => $failed ? 'failed' : 'pending',
            'last_error' => $error,
            'updated_at' => new Timestamp($now),
            'available_at' => $failed ? null : new Timestamp($now->modify("+{$delaySeconds} seconds")),
            'lease_until' => null,
        ], static fn($value) => $value !== null), ['merge' => true]);
    }

    private static function jobId(string $messageId): string
    {
        return 'outbound_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $messageId);
    }

    private static function timestampValue($value): ?int
    {
        if ($value instanceof Timestamp) {
            return $value->get()->getTimestamp();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        if (is_numeric($value)) {
            return (int)$value;
        }
        return null;
    }
}
