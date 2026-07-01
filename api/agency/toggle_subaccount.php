<?php
/**
 * POST|PATCH /api/agency/toggle_subaccount
 *
 * Accepts both calling conventions so the frontend works regardless of
 * which field names / HTTP method it sends:
 *
 *  Modern (POST from agency.ts):
 *    { "location_id": "<id>", "enabled": true|false }
 *
 *  Legacy (PATCH):
 *    { "subaccount_id": "<id>", "enabled": true|false }
 *
 * Delegates fully to the same logic as update_subaccount.php so both
 * collections (agency_subaccounts + ghl_tokens) stay in sync.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

// Accept POST or PATCH — reject everything else
$method = $_SERVER['REQUEST_METHOD'];
if ($method !== 'POST' && $method !== 'PATCH') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method Not Allowed']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request(true);

$raw     = file_get_contents('php://input');
$payload = json_decode($raw, true);

// Normalise field names — accept location_id or subaccount_id
$locationId = $payload['location_id'] ?? $payload['subaccount_id'] ?? null;

if (!$locationId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing location_id']);
    exit;
}

// Normalise toggle field — accept enabled or toggle_enabled
if (isset($payload['enabled'])) {
    $enabled = filter_var($payload['enabled'], FILTER_VALIDATE_BOOLEAN);
} elseif (isset($payload['toggle_enabled'])) {
    $enabled = filter_var($payload['toggle_enabled'], FILTER_VALIDATE_BOOLEAN);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Missing enabled flag']);
    exit;
}

require_once __DIR__ . '/../webhook/firestore_client.php';

function agency_toggle_plan_limit(string $plan): int
{
    $plan = strtolower(trim($plan));
    $limits = [
        'starter' => 1,
        'free' => 1,
        'basic' => 1,
        'growth' => 5,
        'pro' => 5,
        'agency' => 25,
        'professional' => 25,
        'enterprise' => -1,
        'unlimited' => -1,
    ];
    return $limits[$plan] ?? 1;
}

function agency_toggle_parse_subscription_datetime($value): ?DateTimeImmutable
{
    if ($value instanceof \Google\Cloud\Core\Timestamp) {
        return DateTimeImmutable::createFromInterface($value->get());
    }
    if ($value instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($value);
    }
    if (is_string($value) && trim($value) !== '') {
        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable $ignored) {
            return null;
        }
    }
    return null;
}

function agency_toggle_effective_subscription_status(string $status, array $data, array $subscription): string
{
    $normalized = strtolower(trim($status));
    $expiresValue = $subscription['expires_at']
        ?? $subscription['subscription_expires_at']
        ?? $subscription['current_period_end']
        ?? $data['subscription_expires_at']
        ?? $data['expires_at']
        ?? $data['current_period_end']
        ?? null;
    $expiresAt = agency_toggle_parse_subscription_datetime($expiresValue);
    if ($expiresAt !== null && in_array($normalized, ['active', 'trialing'], true) && $expiresAt < new DateTimeImmutable()) {
        return 'expired';
    }
    return $normalized !== '' ? $normalized : 'active';
}

function agency_toggle_subscription_state($db, string $agencyId): array
{
    $data = [];
    try {
        $snap = $db->collection('agency_users')->document($agencyId)->snapshot();
        if ($snap->exists()) {
            $data = $snap->data();
        }
    } catch (\Throwable $ignored) {
    }

    if (!$data) {
        foreach (['company_id', 'agency_id'] as $field) {
            try {
                $docs = $db->collection('agency_users')->where($field, '=', $agencyId)->limit(1)->documents();
                foreach ($docs as $doc) {
                    if ($doc->exists()) {
                        $data = $doc->data();
                        break 2;
                    }
                }
            } catch (\Throwable $ignored) {
            }
        }
    }

    $subscription = is_array($data['subscription'] ?? null) ? $data['subscription'] : [];
    $plan = strtolower(trim((string)($subscription['plan'] ?? $data['subscription_plan'] ?? $data['plan'] ?? 'starter')));
    $status = agency_toggle_effective_subscription_status((string)($subscription['status'] ?? $data['subscription_status'] ?? $data['status'] ?? 'active'), $data, $subscription);
    $limit = $subscription['subaccount_limit']
        ?? $subscription['plan_subaccount_limit']
        ?? $data['plan_subaccount_limit']
        ?? $data['subaccount_limit']
        ?? null;
    if ($limit === null || !is_numeric($limit)) {
        $limit = agency_toggle_plan_limit($plan);
    }

    return ['plan' => $plan, 'status' => $status, 'limit' => (int)$limit];
}

function agency_toggle_active_subaccount_count($db, string $agencyId, string $excludeLocationId): int
{
    $active = 0;
    $docs = $db->collection('agency_subaccounts')->where('agency_id', '=', $agencyId)->documents();
    foreach ($docs as $doc) {
        if (!$doc->exists() || $doc->id() === $excludeLocationId) {
            continue;
        }
        $data = $doc->data();
        if (!array_key_exists('toggle_enabled', $data) || (bool)$data['toggle_enabled']) {
            $active++;
        }
    }
    return $active;
}

try {
    $db     = get_firestore();
    $docRef = $db->collection('agency_subaccounts')->document($locationId);
    $snap   = $docRef->snapshot();

    if (!$snap->exists()) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => 'Subaccount not found']);
        exit;
    }

    $data = $snap->data();

    // Ownership check
    if (trim($data['agency_id'] ?? '') !== trim($agencyId)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'Forbidden: Subaccount does not belong to your agency']);
        exit;
    }

    $currentlyEnabled    = (bool)($data['toggle_enabled'] ?? false);
    $activation_count    = (int)($data['toggle_activation_count'] ?? 0);

    // Enforce subscription capacity and 3-activation limit only when turning ON from OFF
    if ($enabled && !$currentlyEnabled) {
        $subscriptionState = agency_toggle_subscription_state($db, trim((string)$agencyId));
        if (in_array($subscriptionState['status'], ['past_due', 'expired', 'inactive'], true)) {
            http_response_code(403);
            echo json_encode([
                'status' => 'limit_reached',
                'reason' => 'subscription_inactive',
                'subscription_status' => $subscriptionState['status'],
                'message' => 'Subscription inactive. Please renew before enabling more subaccounts.'
            ]);
            exit;
        }

        $planLimit = (int)$subscriptionState['limit'];
        $activeCount = agency_toggle_active_subaccount_count($db, trim((string)$agencyId), $locationId);
        if ($planLimit !== -1 && $activeCount >= $planLimit) {
            http_response_code(403);
            echo json_encode([
                'status' => 'limit_reached',
                'reason' => 'subscription_limit',
                'plan' => $subscriptionState['plan'],
                'limit' => $planLimit,
                'active_subaccounts' => $activeCount,
                'message' => 'Subaccount limit reached for current subscription.'
            ]);
            exit;
        }

        if ($activation_count >= 3) {
            http_response_code(403);
            echo json_encode([
                'status'  => 'limit_reached',
                'error'   => 'Activation Limit Reached',
                'message' => 'Max activation limit reached. Please upgrade to add more.'
            ]);
            exit;
        }
        $activation_count++;
    }

    $updateData = [
        'toggle_enabled'          => $enabled,
        'toggle_activation_count' => $activation_count,
        'updated_at'              => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
    ];

    // Write 1: agency_subaccounts (UI display source)
    $docRef->set($updateData, ['merge' => true]);

    // Write 2: ghl_tokens (enforcement layer for ghl_provider + send_sms)
    $tokenRef  = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();
    if ($tokenSnap->exists()) {
        $tokenRef->set([
            'toggle_enabled' => $enabled,
            'updated_at'     => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable()),
        ], ['merge' => true]);
    }

    try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::invalidateAgencyDashboard($agencyId);
    } catch (\Throwable $e) {
        error_log('[toggle_subaccount] Cache invalidation failed: ' . $e->getMessage());
    }

    echo json_encode([
        'status'                  => 'success',
        'subaccount_id'           => $locationId,
        'toggle_enabled'          => $enabled,
        'toggle_activation_count' => $activation_count,
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Update failed: ' . $e->getMessage()]);
}
