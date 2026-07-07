<?php

/**
 * Read-only Firestore cleanup analysis for NOLA SMS Pro subaccounts.
 *
 * This command recursively inventories Firestore, identifies every location
 * except the three hard-protected production locations, builds relationship
 * edges, and writes JSON/Markdown/CSV dry-run reports. It contains no
 * Firestore mutation calls.
 *
 * Usage:
 *   php scripts/cleanup_analysis.php
 *   php scripts/cleanup_analysis.php --transport=rest --output=tmp/cleanup-analysis
 *   php scripts/cleanup_analysis.php --max-documents=200000
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/services/CleanupSafety.php';

use Google\Cloud\Core\Timestamp;
use Google\Cloud\Firestore\DocumentReference;
use Google\Cloud\Firestore\GeoPoint;

function ca_arg(string $name, ?string $default = null): ?string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }
    return $default;
}

function ca_flag(string $name): bool
{
    global $argv;
    return in_array('--' . $name, $argv, true);
}

function ca_db()
{
    $transport = strtolower(trim((string)ca_arg('transport', getenv('FIRESTORE_TRANSPORT') ?: 'auto')));
    if ($transport === 'rest') {
        return new \Google\Cloud\Firestore\FirestoreClient([
            'projectId' => getenv('GOOGLE_CLOUD_PROJECT') ?: 'nola-sms-pro',
            'transport' => 'rest',
        ]);
    }
    return get_firestore();
}

function ca_location_doc_id(string $locationId): string
{
    return 'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($locationId));
}

function ca_scalar_string($value): string
{
    return is_string($value) || is_numeric($value) ? trim((string)$value) : '';
}

function ca_norm_email($value): string
{
    return strtolower(trim((string)($value ?? '')));
}

function ca_timestamp($value): ?string
{
    if ($value instanceof Timestamp) {
        return $value->get()->format(DATE_ATOM);
    }
    if ($value instanceof DateTimeInterface) {
        return $value->format(DATE_ATOM);
    }
    if (is_string($value) && trim($value) !== '') {
        return trim($value);
    }
    return null;
}

function ca_is_sensitive_key(string $key): bool
{
    return (bool)preg_match('/(?:password|secret|token|api[_-]?key|authorization|credential|private[_-]?key|otp|hash)/i', $key);
}

function ca_safe_value($value, string $key = '', int $depth = 0)
{
    if (ca_is_sensitive_key($key)) {
        return $value === null || $value === '' ? null : '[REDACTED_PRESENT]';
    }
    if ($depth > 3) {
        return '[NESTED_DATA]';
    }
    if ($value instanceof Timestamp || $value instanceof DateTimeInterface) {
        return ca_timestamp($value);
    }
    if ($value instanceof DocumentReference) {
        return $value->path();
    }
    if ($value instanceof GeoPoint) {
        return ['latitude' => $value->latitude(), 'longitude' => $value->longitude()];
    }
    if (is_array($value)) {
        $result = [];
        $count = 0;
        foreach ($value as $k => $v) {
            if ($count++ >= 50) {
                $result['_truncated'] = true;
                break;
            }
            $result[$k] = ca_safe_value($v, (string)$k, $depth + 1);
        }
        return $result;
    }
    if (is_object($value)) {
        return '[OBJECT:' . get_class($value) . ']';
    }
    if (is_string($value) && strlen($value) > 500) {
        return substr($value, 0, 500) . '...[TRUNCATED]';
    }
    return $value;
}

function ca_summary(array $data): array
{
    $preferred = [
        'name', 'location_name', 'company_name', 'email', 'role', 'active',
        'status', 'install_state', 'install_status', 'is_live', 'toggle_enabled',
        'location_id', 'locationId', 'ghl_location_id', 'active_location_id',
        'companyId', 'company_id', 'agency_id', 'account_id', 'subaccount_id',
        'user_id', 'owner_user_id', 'conversation_id', 'sender_id', 'requested_id',
        'approved_sender_id', 'balance', 'credits', 'credit_balance', 'amount',
        'subject', 'type', 'scope', 'created_at', 'updated_at', 'last_login',
        'uninstalled_at', 'last_message_at', 'scheduled_at', 'run_at',
    ];
    $out = [];
    foreach ($preferred as $key) {
        if (array_key_exists($key, $data)) {
            $out[$key] = ca_safe_value($data[$key], $key);
        }
    }
    foreach ($data as $key => $value) {
        if (ca_is_sensitive_key((string)$key)) {
            $out[(string)$key] = ca_safe_value($value, (string)$key);
        }
    }
    return $out;
}

function ca_collect_exact_strings($value, string $key = '', string $prefix = ''): array
{
    $result = [];
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $child = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            $result += ca_collect_exact_strings($v, (string)$k, $child);
        }
    } elseif (is_string($value) || is_numeric($value)) {
        $s = trim((string)$value);
        if ($s !== '') {
            $result[$prefix !== '' ? $prefix : $key] = $s;
        }
    } elseif ($value instanceof DocumentReference) {
        $result[$prefix !== '' ? $prefix : $key] = $value->path();
    }
    return $result;
}

function ca_location_values(array $data): array
{
    $keys = [
        'location_id', 'locationId', 'ghl_location_id', 'ghlLocationId',
        'active_location_id', 'activeLocationId', 'source_location_id',
        'nola_sms_source_location_id', 'subaccount_id', 'subAccountId',
    ];
    $ids = [];
    foreach ($keys as $key) {
        $value = $data[$key] ?? null;
        foreach (is_array($value) ? $value : [$value] as $item) {
            $s = ca_scalar_string($item);
            if ($s !== '') {
                $ids[] = str_starts_with($s, 'ghl_') ? substr($s, 4) : $s;
            }
        }
    }
    $tokenRef = ca_scalar_string($data['ghl_token_ref'] ?? '');
    if (preg_match('#(?:^|/)ghl_tokens/([^/]+)$#', $tokenRef, $m)) {
        $ids[] = $m[1];
    }
    return array_values(array_unique($ids));
}

function ca_company_values(array $data): array
{
    $values = [];
    foreach (['companyId', 'company_id', 'agency_id', 'agencyId'] as $key) {
        $s = ca_scalar_string($data[$key] ?? '');
        if ($s !== '') {
            $values[] = $s;
        }
    }
    return array_values(array_unique($values));
}

function ca_category(string $collection): string
{
    $map = [
        'users' => 'users', 'location_owners' => 'ownership_links',
        'location_user_links' => 'ownership_links', 'members' => 'ownership_links',
        'subaccounts' => 'ownership_links', 'ghl_tokens' => 'oauth_tokens',
        'sender_id_requests' => 'sender_ids', 'contacts' => 'contacts',
        'conversations' => 'conversations', 'messages' => 'messages',
        'inbound_messages' => 'messages', 'sms_logs' => 'sms_logs',
        'accounts' => 'credits', 'credit_requests' => 'credits',
        'credit_transactions' => 'credits', 'agency_wallet' => 'credits',
        'integrations' => 'integrations', 'templates' => 'templates',
        'support_tickets' => 'tickets', 'admin_notifications' => 'notifications',
        'install_sessions' => 'scheduled_jobs', 'install_selection_claims' => 'scheduled_jobs',
        'marketplace_install_picks' => 'scheduled_jobs', 'auto_recharge_attempts' => 'scheduled_jobs',
        'sync_cursors' => 'scheduled_jobs', 'ghl_sync_dedup' => 'scheduled_jobs',
        'idempotency_keys' => 'scheduled_jobs', 'subscription_events' => 'credits',
        'admin_logs' => 'logs', 'logs' => 'logs',
        'agency_subaccounts' => 'subaccount_core',
    ];
    return $map[$collection] ?? 'other_related_data';
}

function ca_is_pending(string $collection, array $data): bool
{
    $status = strtolower(ca_scalar_string($data['status'] ?? $data['install_state'] ?? ''));
    if (in_array($status, ['pending', 'queued', 'sending', 'processing', 'provisioning', 'pending_payment_provider'], true)) {
        return true;
    }
    return $collection === 'support_tickets' && in_array($status, ['open', 'pending'], true);
}

function ca_nonzero_balance(string $collection, array $data): bool
{
    if (!in_array($collection, ['accounts', 'integrations'], true)) {
        return false;
    }
    foreach (['balance', 'credit_balance', 'credits', 'current_balance', 'wallet_balance'] as $field) {
        if (isset($data[$field]) && is_numeric($data[$field]) && (float)$data[$field] != 0.0) {
            return true;
        }
    }
    return false;
}

function ca_report_action(string $collection, array $data, bool $sharedProduction, bool $sharedAgency, bool $sharedUser): array
{
    if ($sharedProduction || $sharedUser) {
        return ['retain_shared_dependency', 'References both a cleanup candidate and protected production data.'];
    }
    if ($sharedAgency || in_array($collection, ['agency_wallet', 'agency_users', 'subscription_events'], true)) {
        return ['retain_agency_level', 'Agency/company-level data may serve production subaccounts.'];
    }
    if (in_array($collection, ['credit_transactions', 'credit_requests'], true)) {
        return ['retain_financial_history', 'Immutable billing/audit history is retained by default.'];
    }
    if (ca_nonzero_balance($collection, $data)) {
        return ['manual_review_nonzero_balance', 'Nonzero candidate balance must be transferred, refunded, or explicitly forfeited before deletion.'];
    }
    if (ca_is_pending($collection, $data)) {
        return ['manual_review_pending', 'Pending or in-flight work must be resolved before deletion.'];
    }
    return ['would_delete', 'Candidate-scoped document with no detected production dependency.'];
}

function ca_scan_collection($collection, array &$docs, int &$count, int $max, bool &$complete): void
{
    // Static analysis of all collection() calls in api/, scripts/, and tmp/
    // shows nested runtime collections only beneath these parents:
    // integrations/{id}/templates, users/{id}/subaccounts, and
    // location_owners/{id}/members. Calling ListCollectionIds for every SMS log
    // and message turns a read-only audit into thousands of needless RPCs.
    $nestedParentCollections = ['integrations', 'users', 'location_owners'];
    foreach ($collection->documents() as $snapshot) {
        if ($count >= $max) {
            $complete = false;
            return;
        }
        if (!$snapshot->exists()) {
            continue;
        }
        $count++;
        $path = $snapshot->reference()->path();
        $docs[$path] = [
            'path' => $path,
            'id' => $snapshot->id(),
            'collection' => $collection->id(),
            'data' => $snapshot->data(),
        ];
        if (in_array($collection->id(), $nestedParentCollections, true)) {
            foreach ($snapshot->reference()->collections() as $child) {
                ca_scan_collection($child, $docs, $count, $max, $complete);
                if (!$complete) {
                    return;
                }
            }
        }
    }
}

function ca_csv_cell($value): string
{
    $s = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return '"' . str_replace('"', '""', (string)$s) . '"';
}

if (ca_flag('help') || ca_flag('h')) {
    echo "Read-only cleanup analyzer.\n";
    echo "Usage: php scripts/cleanup_analysis.php [--transport=rest] [--output=DIR] [--max-documents=N]\n";
    exit(0);
}

$started = microtime(true);
$maxDocuments = max(1000, (int)ca_arg('max-documents', '200000'));
$outputRoot = rtrim((string)ca_arg('output', __DIR__ . '/../tmp/cleanup-analysis'), '/\\');
$runId = gmdate('Ymd_His') . '_utc';
$outputDir = $outputRoot . DIRECTORY_SEPARATOR . $runId;

try {
    $db = ca_db();
    $docs = [];
    $documentCount = 0;
    $scanComplete = true;
    $topCollections = [];

    fwrite(STDOUT, "Scanning Firestore recursively (read-only)...\n");
    foreach ($db->collections() as $collection) {
        $topCollections[] = $collection->id();
        ca_scan_collection($collection, $docs, $documentCount, $maxDocuments, $scanComplete);
        if (!$scanComplete) {
            break;
        }
    }

    $productionIds = array_keys(CleanupSafety::PROTECTED_LOCATIONS);
    $protectedCompanies = CleanupSafety::PROTECTED_COMPANIES;
    $candidateMeta = [];

    foreach ($docs as $doc) {
        $data = $doc['data'];
        $collection = $doc['collection'];
        $seeds = [];
        if ($collection === 'agency_subaccounts' || $collection === 'location_owners') {
            $seeds[] = $doc['id'];
        } elseif ($collection === 'ghl_tokens') {
            $isAgency = strtolower(ca_scalar_string($data['appType'] ?? '')) === 'agency'
                || strtolower(ca_scalar_string($data['userType'] ?? '')) === 'company';
            if (!$isAgency) {
                $seeds = array_merge($seeds, ca_location_values($data));
                $seeds[] = $doc['id'];
            }
        } elseif ($collection === 'integrations') {
            $locs = ca_location_values($data);
            if ($locs !== []) {
                $seeds = array_merge($seeds, $locs);
            } elseif (str_starts_with($doc['id'], 'ghl_')) {
                $seeds[] = substr($doc['id'], 4);
            }
        }
        foreach ($seeds as $locationId) {
            $locationId = trim((string)$locationId);
            if ($locationId === '' || in_array($locationId, $productionIds, true) || in_array($locationId, $protectedCompanies, true)) {
                continue;
            }
            $candidateMeta[$locationId] ??= [
                'location_id' => $locationId,
                'name' => null,
                'company_ids' => [],
                'seed_paths' => [],
                'token_registry_path' => null,
                'observed_oauth_client_id' => null,
                'token_appears_usable' => false,
            ];
            $candidateMeta[$locationId]['seed_paths'][] = $doc['path'];
            $name = ca_scalar_string($data['location_name'] ?? $data['name'] ?? '');
            if ($name !== '') {
                $candidateMeta[$locationId]['name'] = $name;
            }
            $candidateMeta[$locationId]['company_ids'] = array_values(array_unique(array_merge(
                $candidateMeta[$locationId]['company_ids'], ca_company_values($data)
            )));
            if ($collection === 'ghl_tokens') {
                $candidateMeta[$locationId]['token_registry_path'] = $doc['path'];
                $clientId = ca_scalar_string($data['client_id'] ?? $data['appId'] ?? '');
                if ($clientId !== '') {
                    $candidateMeta[$locationId]['observed_oauth_client_id'] = $clientId;
                }
                $candidateMeta[$locationId]['token_appears_usable'] =
                    ca_scalar_string($data['access_token'] ?? '') !== ''
                    || ca_scalar_string($data['refresh_token'] ?? '') !== '';
            }
        }
    }

    $candidateIds = array_keys($candidateMeta);
    $allLocationIds = array_values(array_unique(array_merge($candidateIds, $productionIds)));
    $docLocations = [];
    $userLocationMap = [];
    $userEmailMap = [];
    $conversationLocationMap = [];

    foreach ($docs as $path => $doc) {
        $data = $doc['data'];
        $refs = ca_location_values($data);
        foreach ($allLocationIds as $loc) {
            if ($doc['id'] === $loc || $doc['id'] === ca_location_doc_id($loc)) {
                $refs[] = $loc;
            }
        }
        $segments = explode('/', $path);
        foreach ($allLocationIds as $loc) {
            if (in_array($loc, $segments, true) || in_array(ca_location_doc_id($loc), $segments, true)) {
                $refs[] = $loc;
            }
        }
        $strings = ca_collect_exact_strings($data);
        foreach ($strings as $value) {
            foreach ($allLocationIds as $loc) {
                if ($value === $loc || $value === ca_location_doc_id($loc) || $value === 'ghl_tokens/' . $loc) {
                    $refs[] = $loc;
                }
            }
        }
        $docLocations[$path] = array_values(array_unique($refs));
        if ($doc['collection'] === 'users') {
            $userLocationMap[$doc['id']] = $docLocations[$path];
            $email = ca_norm_email($data['email'] ?? '');
            if ($email !== '') {
                $userEmailMap[$email] = array_values(array_unique(array_merge($userEmailMap[$email] ?? [], $docLocations[$path])));
            }
        }
        if ($doc['collection'] === 'conversations') {
            $conversationLocationMap[$doc['id']] = $docLocations[$path];
        }
    }

    // Second pass: inherit relationships from conversations, users, integration
    // subcollections, and ownership member subcollections.
    foreach ($docs as $path => $doc) {
        $data = $doc['data'];
        $refs = $docLocations[$path];
        $conversationId = ca_scalar_string($data['conversation_id'] ?? $data['conversationId'] ?? '');
        if ($conversationId !== '' && isset($conversationLocationMap[$conversationId])) {
            $refs = array_merge($refs, $conversationLocationMap[$conversationId]);
        }
        $userId = ca_scalar_string($data['user_id'] ?? $data['userId'] ?? $data['owner_user_id'] ?? $data['uid'] ?? '');
        if ($userId !== '' && isset($userLocationMap[$userId])) {
            $refs = array_merge($refs, $userLocationMap[$userId]);
        }
        $segments = explode('/', $path);
        if (count($segments) >= 3 && $segments[0] === 'users' && isset($userLocationMap[$segments[1]])) {
            $refs = array_merge($refs, $userLocationMap[$segments[1]]);
        }
        $docLocations[$path] = array_values(array_unique($refs));
    }

    $senderUsage = [];
    foreach ($docs as $path => $doc) {
        foreach (['sender_id', 'sender_name', 'requested_id', 'approved_sender_id'] as $field) {
            $sender = strtolower(ca_scalar_string($doc['data'][$field] ?? ''));
            if ($sender !== '') {
                foreach ($docLocations[$path] as $loc) {
                    $senderUsage[$sender][$loc] = true;
                }
            }
        }
    }

    $reports = [];
    $associationCounts = [];
    foreach ($candidateMeta as $locationId => $meta) {
        $rows = [];
        $flags = [];
        $companyIds = $meta['company_ids'];
        foreach ($docs as $path => $doc) {
            if (!in_array($locationId, $docLocations[$path], true)) {
                continue;
            }
            $locations = $docLocations[$path];
            $productionRefs = array_values(array_intersect($locations, $productionIds));
            $data = $doc['data'];
            $companies = ca_company_values($data);
            $sharedAgency = false;
            foreach ($companies as $companyId) {
                if (in_array($companyId, $protectedCompanies, true)
                    && in_array($doc['collection'], ['agency_wallet', 'agency_users', 'subscription_events'], true)) {
                    $sharedAgency = true;
                }
            }
            $userId = $doc['collection'] === 'users' ? $doc['id'] : ca_scalar_string($data['user_id'] ?? $data['owner_user_id'] ?? '');
            $sharedUser = $userId !== '' && !empty(array_intersect($userLocationMap[$userId] ?? [], $productionIds));
            $email = ca_norm_email($data['email'] ?? $data['user_email'] ?? $data['owner_email'] ?? '');
            if ($email !== '' && !empty(array_intersect($userEmailMap[$email] ?? [], $productionIds))) {
                $sharedUser = true;
            }
            [$action, $reason] = ca_report_action($doc['collection'], $data, $productionRefs !== [], $sharedAgency, $sharedUser);
            $category = ca_category($doc['collection']);
            $rowFlags = [];
            if ($productionRefs !== []) {
                $rowFlags[] = 'references_production:' . implode(',', $productionRefs);
            }
            if ($sharedUser) {
                $rowFlags[] = 'user_or_email_shared_with_production';
            }
            if ($sharedAgency) {
                $rowFlags[] = 'agency_level_dependency';
            }
            foreach (['sender_id', 'sender_name', 'requested_id', 'approved_sender_id'] as $field) {
                $sender = strtolower(ca_scalar_string($data[$field] ?? ''));
                if ($sender !== '' && !empty(array_intersect(array_keys($senderUsage[$sender] ?? []), $productionIds))) {
                    $rowFlags[] = 'sender_value_also_used_by_production:' . $sender;
                }
            }
            if (ca_is_pending($doc['collection'], $data)) {
                $rowFlags[] = 'pending_or_in_flight';
            }
            $rows[] = [
                'path' => $path,
                'collection' => $doc['collection'],
                'category' => $category,
                'action' => $action,
                'reason' => $reason,
                'flags' => array_values(array_unique($rowFlags)),
                'related_locations' => $locations,
                'summary' => ca_summary($data),
            ];
            $associationCounts[$action] = ($associationCounts[$action] ?? 0) + 1;
            foreach ($rowFlags as $flag) {
                $flags[$flag] = true;
            }
        }
        usort($rows, static fn(array $a, array $b): int => [$a['category'], $a['path']] <=> [$b['category'], $b['path']]);
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['category']][$row['action']] = ($counts[$row['category']][$row['action']] ?? 0) + 1;
        }
        $reports[$locationId] = [
            'location_id' => $locationId,
            'name' => $meta['name'],
            'company_ids' => $companyIds,
            'seed_paths' => array_values(array_unique($meta['seed_paths'])),
            'flags' => array_keys($flags),
            'counts' => $counts,
            'remote_uninstall' => [
                'required' => true,
                // OAuth client IDs stored in ghl_tokens are not assumed to be
                // the Marketplace application ID required by the uninstall API.
                'app_id' => ca_scalar_string(getenv('GHL_MARKETPLACE_APP_ID') ?: '') ?: null,
                'app_id_source' => getenv('GHL_MARKETPLACE_APP_ID') ? 'environment' : 'operator_required',
                'observed_oauth_client_id' => $meta['observed_oauth_client_id'],
                'token_registry_path' => $meta['token_registry_path'],
                'token_appears_usable' => $meta['token_appears_usable'],
            ],
            'documents' => $rows,
        ];
    }

    ksort($reports);
    ksort($associationCounts);

    // A shared document can be associated with many candidates. Produce a
    // path-deduplicated final decision so a future cleanup has one unambiguous
    // instruction per Firestore document.
    $decisionPriority = [
        'retain_shared_dependency' => 100,
        'retain_agency_level' => 90,
        'retain_financial_history' => 80,
        'manual_review_nonzero_balance' => 75,
        'manual_review_pending' => 70,
        'would_delete' => 10,
    ];
    $uniqueDecisions = [];
    foreach ($reports as $locationId => $report) {
        foreach ($report['documents'] as $row) {
            $path = $row['path'];
            if (!isset($uniqueDecisions[$path])) {
                $uniqueDecisions[$path] = [
                    'path' => $path,
                    'collection' => $row['collection'],
                    'category' => $row['category'],
                    'final_action' => $row['action'],
                    'candidate_ids' => [],
                    'observed_actions' => [],
                    'flags' => [],
                ];
            }
            $uniqueDecisions[$path]['candidate_ids'][] = $locationId;
            $uniqueDecisions[$path]['observed_actions'][] = $row['action'];
            $uniqueDecisions[$path]['flags'] = array_merge($uniqueDecisions[$path]['flags'], $row['flags']);
            $current = $uniqueDecisions[$path]['final_action'];
            if (($decisionPriority[$row['action']] ?? 0) > ($decisionPriority[$current] ?? 0)) {
                $uniqueDecisions[$path]['final_action'] = $row['action'];
            }
        }
    }
    foreach ($uniqueDecisions as &$decision) {
        $decision['candidate_ids'] = array_values(array_unique($decision['candidate_ids']));
        $decision['observed_actions'] = array_values(array_unique($decision['observed_actions']));
        $decision['flags'] = array_values(array_unique($decision['flags']));
    }
    unset($decision);
    ksort($uniqueDecisions);
    $uniqueActionCounts = [];
    $wouldDeletePaths = [];
    foreach ($uniqueDecisions as $decision) {
        $action = $decision['final_action'];
        $uniqueActionCounts[$action] = ($uniqueActionCounts[$action] ?? 0) + 1;
        if ($action === 'would_delete') {
            $wouldDeletePaths[] = $decision['path'];
        }
    }
    ksort($uniqueActionCounts);
    $manifest = [
        'manifest_version' => CleanupSafety::MANIFEST_VERSION,
        'report_type' => 'NOLA SMS Pro read-only cleanup analysis',
        'dry_run' => true,
        'firestore_mutations_performed' => 0,
        'generated_at' => gmdate(DATE_ATOM),
        'production_allowlist' => CleanupSafety::PROTECTED_LOCATIONS,
        'protected_company_ids' => CleanupSafety::PROTECTED_COMPANIES,
        'scan' => [
            'complete' => $scanComplete,
            'documents_scanned' => $documentCount,
            'max_documents' => $maxDocuments,
            'top_level_collections' => $topCollections,
            'recursive_parent_collections' => ['integrations', 'users', 'location_owners'],
            'duration_seconds' => round(microtime(true) - $started, 3),
        ],
        'candidate_count' => count($reports),
        'association_action_counts' => $associationCounts,
        'unique_document_action_counts' => $uniqueActionCounts,
        'policy' => [
            'would_delete' => 'Candidate-scoped data with no detected production dependency.',
            'retain_shared_dependency' => 'Touches protected production data; must not be deleted.',
            'retain_agency_level' => 'Shared agency/company record; must not be deleted with a location.',
            'retain_financial_history' => 'Billing/audit history retained by default.',
            'manual_review_nonzero_balance' => 'Nonzero balance must be resolved before deletion.',
            'manual_review_pending' => 'Pending/in-flight work must be resolved first.',
        ],
        'unique_document_decisions' => array_values($uniqueDecisions),
        'would_delete_paths' => $wouldDeletePaths,
        'candidates' => $reports,
    ];
    $manifest['manifest_sha256'] = CleanupSafety::manifestDigest($manifest);

    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
        throw new RuntimeException('Unable to create output directory: ' . $outputDir);
    }
    $jsonPath = $outputDir . DIRECTORY_SEPARATOR . 'cleanup-analysis.json';
    file_put_contents($jsonPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $csv = [implode(',', array_map('ca_csv_cell', ['location_id', 'location_name', 'category', 'collection', 'document_path', 'action', 'reason', 'flags', 'summary']))];
    foreach ($reports as $report) {
        foreach ($report['documents'] as $row) {
            $csv[] = implode(',', array_map('ca_csv_cell', [
                $report['location_id'], $report['name'], $row['category'], $row['collection'],
                $row['path'], $row['action'], $row['reason'], $row['flags'], $row['summary'],
            ]));
        }
    }
    $csvPath = $outputDir . DIRECTORY_SEPARATOR . 'cleanup-analysis.csv';
    file_put_contents($csvPath, implode("\r\n", $csv) . "\r\n");

    $deleteManifestPath = $outputDir . DIRECTORY_SEPARATOR . 'would-delete.txt';
    file_put_contents($deleteManifestPath, implode("\n", $wouldDeletePaths) . ($wouldDeletePaths ? "\n" : ''));

    $md = [];
    $md[] = '# NOLA SMS Pro Cleanup Analysis';
    $md[] = '';
    $md[] = '> DRY RUN — read-only analysis. Firestore mutations performed: **0**.';
    $md[] = '';
    $md[] = '- Generated: `' . $manifest['generated_at'] . '`';
    $md[] = '- Scan complete: **' . ($scanComplete ? 'yes' : 'NO — document limit reached') . '**';
    $md[] = '- Documents scanned: **' . $documentCount . '**';
    $md[] = '- Cleanup candidates: **' . count($reports) . '**';
    $md[] = '';
    $md[] = '## Protected production locations';
    $md[] = '';
    foreach (CleanupSafety::PROTECTED_LOCATIONS as $id => $name) {
        $md[] = '- ' . $name . ': `' . $id . '`';
    }
    $md[] = '';
    $md[] = '## Dry-run totals';
    $md[] = '';
    foreach ($uniqueActionCounts as $action => $count) {
        $md[] = '- `' . $action . '`: **' . $count . '** documents';
    }
    $md[] = '';
    $md[] = '## Candidates';
    foreach ($reports as $report) {
        $md[] = '';
        $md[] = '### ' . ($report['name'] ?: 'Unnamed subaccount') . ' — `' . $report['location_id'] . '`';
        $md[] = '';
        $md[] = '- Company/agency IDs: ' . ($report['company_ids'] ? '`' . implode('`, `', $report['company_ids']) . '`' : 'none detected');
        $md[] = '- Dependency flags: ' . ($report['flags'] ? '`' . implode('`, `', $report['flags']) . '`' : 'none');
        $md[] = '';
        $md[] = '| Category | Action | Document | Flags |';
        $md[] = '|---|---|---|---|';
        foreach ($report['documents'] as $row) {
            $md[] = '| ' . $row['category'] . ' | `' . $row['action'] . '` | `' . str_replace('|', '\\|', $row['path']) . '` | ' . ($row['flags'] ? implode('; ', $row['flags']) : '') . ' |';
        }
    }
    $mdPath = $outputDir . DIRECTORY_SEPARATOR . 'cleanup-analysis.md';
    file_put_contents($mdPath, implode("\n", $md) . "\n");

    fwrite(STDOUT, "Scan complete=" . ($scanComplete ? 'yes' : 'no') . " docs={$documentCount} candidates=" . count($reports) . "\n");
    fwrite(STDOUT, "JSON: {$jsonPath}\nMarkdown: {$mdPath}\nCSV: {$csvPath}\nWould-delete manifest: {$deleteManifestPath}\n");
    exit($scanComplete ? 0 : 2);
} catch (Throwable $e) {
    fwrite(STDERR, 'Cleanup analysis failed: ' . $e->getMessage() . "\n");
    exit(1);
}
