<?php

/**
 * Expire abandoned PENDING_OAUTH location installs.
 *
 * Dry-run by default:
 *   php scripts/reconcile_pending_installs.php
 *
 * Apply after reviewing the output:
 *   php scripts/reconcile_pending_installs.php --apply --older-than-hours=24
 *
 * This reconciles NOLA state only. HighLevel owns Marketplace/sidebar
 * visibility, so an expired location may remain visible until reinstalled or
 * explicitly uninstalled in HighLevel.
 */

require_once __DIR__ . '/../api/webhook/firestore_client.php';
require_once __DIR__ . '/../api/install_helpers.php';
require_once __DIR__ . '/../api/services/InstallLifecyclePolicy.php';

function pending_reconcile_arg(string $name, $default = null)
{
    global $argv;
    foreach ($argv as $arg) {
        if ($arg === '--' . $name) {
            return true;
        }
        $prefix = '--' . $name . '=';
        if (str_starts_with($arg, $prefix)) {
            return substr($arg, strlen($prefix));
        }
    }
    return $default;
}

$apply = pending_reconcile_arg('apply', false) === true;
$hours = max(1, min(720, (int)pending_reconcile_arg('older-than-hours', 24)));
$limit = max(1, min(1000, (int)pending_reconcile_arg('limit', 200)));
$ttlSeconds = $hours * 3600;
$now = new DateTimeImmutable();
$nowUnix = $now->getTimestamp();
$timestamp = new \Google\Cloud\Core\Timestamp($now);
$db = get_firestore();

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'older_than_hours' => $hours,
    'scanned' => 0,
    'eligible' => 0,
    'expired' => 0,
    'skipped_linked' => 0,
    'skipped_not_stale' => 0,
    'errors' => 0,
];

$docs = $db->collection('ghl_tokens')
    ->where('install_state', '=', INSTALL_STATE_PENDING_OAUTH)
    ->limit($limit)
    ->documents();

foreach ($docs as $doc) {
    if (!$doc->exists()) {
        continue;
    }

    $summary['scanned']++;
    $locationId = (string)$doc->id();
    $data = $doc->data();

    if (!InstallLifecyclePolicy::pendingIsStale($data, $nowUnix, $ttlSeconds)) {
        $summary['skipped_not_stale']++;
        continue;
    }

    try {
        $linked = install_linked_account_for_location($db, $locationId, false);
        if ($linked !== null) {
            $summary['skipped_linked']++;
            echo "SKIP linked {$locationId}\n";
            continue;
        }

        $summary['eligible']++;
        $startedAt = InstallLifecyclePolicy::pendingStartedAt($data);
        echo ($apply ? 'EXPIRE ' : 'WOULD_EXPIRE ') . $locationId
            . ' pending_since=' . ($startedAt !== null ? gmdate('c', $startedAt) : 'unknown') . "\n";

        if (!$apply) {
            continue;
        }

        $expiredData = [
            'install_state' => INSTALL_STATE_ONBOARDING_EXPIRED,
            'install_status' => INSTALL_STATE_ONBOARDING_EXPIRED,
            'is_live' => false,
            'toggle_enabled' => false,
            'onboarding_expired_at' => $timestamp,
            'onboarding_expiry_reason' => 'pending_oauth_ttl_exceeded',
            'updated_at' => $timestamp,
            'access_token' => null,
            'refresh_token' => null,
        ];

        $batch = $db->batch();
        $batch->set($doc->reference(), $expiredData, ['merge' => true]);

        $integrationRef = $db->collection('integrations')->document(
            'ghl_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $locationId)
        );
        $batch->set($integrationRef, [
            'location_id' => $locationId,
            'install_state' => INSTALL_STATE_ONBOARDING_EXPIRED,
            'is_live' => false,
            'onboarding_expired_at' => $timestamp,
            'updated_at' => $timestamp,
            'access_token' => null,
            'refresh_token' => null,
        ], ['merge' => true]);

        $subaccountRef = $db->collection('agency_subaccounts')->document($locationId);
        $batch->set($subaccountRef, [
            'toggle_enabled' => false,
            'install_state' => INSTALL_STATE_ONBOARDING_EXPIRED,
            'onboarding_expired_at' => $timestamp,
            'updated_at' => $timestamp,
        ], ['merge' => true]);

        $pendingOwnerId = trim((string)($data['owner_user_id'] ?? $data['owner_uid'] ?? ''));
        if ($pendingOwnerId !== '') {
            $userRef = $db->collection('users')->document($pendingOwnerId);
            $batch->set($userRef, [
                'active' => false,
                'activation_state' => 'expired',
                'activation_expired_at' => $timestamp,
                'updated_at' => $timestamp,
            ], ['merge' => true]);
            $batch->set($userRef->collection('subaccounts')->document($locationId), [
                'is_active' => false,
                'activation_state' => 'expired',
                'updated_at' => $timestamp,
            ], ['merge' => true]);
        }

        $batch->commit();
        $summary['expired']++;
    } catch (Throwable $e) {
        $summary['errors']++;
        fwrite(STDERR, "ERROR {$locationId}: {$e->getMessage()}\n");
    }
}

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($summary['errors'] > 0 ? 1 : 0);
