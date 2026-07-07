<?php

/**
 * Pure cleanup policy and manifest validation helpers.
 *
 * Keep this class free of Firestore/network calls so destructive tooling can
 * validate its input before opening either dependency.
 */
final class CleanupSafety
{
    public const MANIFEST_VERSION = 1;

    public const PROTECTED_LOCATIONS = [
        'ugBqfQsPtGijLjrmLdmA' => 'NOLA CRM',
        'UorU5d43qIWssU2z55fO' => 'Maxiemizer',
        'Is3CjRqD4xzqonUZIOEo' => 'J&K Car Rental - Angeles City',
    ];

    public const PROTECTED_COMPANIES = [
        '0OYXPGWM9ep2I37dgxAo',
        'xFF0ibpAlZA1Ol9QXpsf',
    ];

    public const EXECUTION_CONFIRMATION = 'DELETE-NOLA-TEST-DATA';

    public const DELETABLE_COLLECTIONS = [
        'accounts', 'admin_notifications', 'agency_subaccounts', 'contacts',
        'conversations', 'ghl_sync_dedup', 'ghl_tokens', 'idempotency_keys',
        'inbound_messages', 'install_selection_claims', 'install_sessions',
        'integrations', 'location_members', 'location_owners', 'messages',
        'sender_id_requests', 'sms_logs', 'subaccounts', 'sync_cursors',
        'templates', 'users',
    ];

    public static function isProtectedLocation(string $locationId): bool
    {
        return array_key_exists(trim($locationId), self::PROTECTED_LOCATIONS);
    }

    public static function isProtectedCompany(string $companyId): bool
    {
        return in_array(trim($companyId), self::PROTECTED_COMPANIES, true);
    }

    /**
     * Produce a stable digest without depending on JSON property insertion order.
     */
    public static function manifestDigest(array $manifest): string
    {
        unset($manifest['manifest_sha256']);
        $canonical = self::canonicalize($manifest);
        return hash('sha256', json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    public static function validateManifest(array $manifest, string $expectedDigest, int $maxAgeSeconds = 86400): array
    {
        $errors = [];
        if (($manifest['dry_run'] ?? null) !== true) {
            $errors[] = 'Manifest is not a dry-run analysis.';
        }
        if (($manifest['firestore_mutations_performed'] ?? null) !== 0) {
            $errors[] = 'Manifest reports Firestore mutations.';
        }
        if (($manifest['scan']['complete'] ?? false) !== true) {
            $errors[] = 'Manifest scan is incomplete.';
        }
        if ((int)($manifest['manifest_version'] ?? 0) !== self::MANIFEST_VERSION) {
            $errors[] = 'Unsupported or missing manifest version.';
        }

        $storedDigest = strtolower(trim((string)($manifest['manifest_sha256'] ?? '')));
        $expectedDigest = strtolower(trim($expectedDigest));
        $actualDigest = self::manifestDigest($manifest);
        if ($storedDigest === '' || !hash_equals($storedDigest, $actualDigest)) {
            $errors[] = 'Manifest content digest is invalid.';
        }
        if ($expectedDigest === '' || !hash_equals($actualDigest, $expectedDigest)) {
            $errors[] = 'Provided manifest digest does not match.';
        }

        $generatedAt = strtotime((string)($manifest['generated_at'] ?? ''));
        if ($generatedAt === false) {
            $errors[] = 'Manifest generation time is invalid.';
        } elseif ($maxAgeSeconds > 0 && time() - $generatedAt > $maxAgeSeconds) {
            $errors[] = 'Manifest is stale; generate a new analysis.';
        } elseif ($generatedAt > time() + 300) {
            $errors[] = 'Manifest generation time is in the future.';
        }

        $manifestProtected = $manifest['production_allowlist'] ?? [];
        if (!is_array($manifestProtected) || array_diff_key(self::PROTECTED_LOCATIONS, $manifestProtected) !== []) {
            $errors[] = 'Manifest is missing one or more protected production locations.';
        }

        return $errors;
    }

    public static function candidateMap(array $manifest): array
    {
        $map = [];
        foreach (($manifest['candidates'] ?? []) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $id = trim((string)($candidate['location_id'] ?? ''));
            if ($id !== '') {
                $map[$id] = $candidate;
            }
        }
        return $map;
    }

    public static function candidateBlockers(array $candidate): array
    {
        $blockers = [];
        $locationId = trim((string)($candidate['location_id'] ?? ''));
        if ($locationId === '' || self::isProtectedLocation($locationId)) {
            $blockers[] = 'protected_or_invalid_location';
        }
        $counts = is_array($candidate['counts'] ?? null) ? $candidate['counts'] : [];
        $actionTotals = [];
        foreach ($counts as $categoryCounts) {
            if (!is_array($categoryCounts)) {
                continue;
            }
            foreach ($categoryCounts as $action => $count) {
                $actionTotals[$action] = ($actionTotals[$action] ?? 0) + (int)$count;
            }
        }
        if ((int)($actionTotals['manual_review_nonzero_balance'] ?? 0) > 0) {
            $blockers[] = 'nonzero_balance';
        }
        if ((int)($actionTotals['manual_review_pending'] ?? 0) > 0) {
            $blockers[] = 'pending_work';
        }
        return array_values(array_unique($blockers));
    }

    /**
     * Return deletable decisions whose complete candidate set is approved.
     * This prevents a one-location canary deleting data shared by another
     * candidate that has not yet been approved/uninstalled.
     */
    public static function approvedDeletionDecisions(array $manifest, array $approvedLocationIds): array
    {
        $approved = array_fill_keys(array_values(array_unique(array_map('strval', $approvedLocationIds))), true);
        $result = [];
        foreach (($manifest['unique_document_decisions'] ?? []) as $decision) {
            if (!is_array($decision) || ($decision['final_action'] ?? '') !== 'would_delete') {
                continue;
            }
            if (!in_array((string)($decision['collection'] ?? ''), self::DELETABLE_COLLECTIONS, true)) {
                continue;
            }
            $candidateIds = array_values(array_unique(array_map('strval', $decision['candidate_ids'] ?? [])));
            if ($candidateIds === []) {
                continue;
            }
            $allApproved = true;
            foreach ($candidateIds as $candidateId) {
                if (!isset($approved[$candidateId])) {
                    $allApproved = false;
                    break;
                }
            }
            if ($allApproved) {
                $result[] = $decision;
            }
        }
        return $result;
    }

    private static function canonicalize($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'canonicalize'], $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}
