<?php
/**
 * POST /api/agency/update_subaccount
 * 
 * Updates the SMS settings (toggle, rate limit, attempt resets) 
 * for a specific subaccount inside ghl_tokens.
 */
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');
require __DIR__ . '/../webhook/firestore_client.php';

function agency_update_plan_limit(string $plan): int
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

function agency_update_parse_subscription_datetime($value): ?DateTimeImmutable
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

function agency_update_effective_subscription_status(string $status, array $data, array $subscription): string
{
    $normalized = strtolower(trim($status));
    $expiresValue = $subscription['expires_at']
        ?? $subscription['subscription_expires_at']
        ?? $subscription['current_period_end']
        ?? $data['subscription_expires_at']
        ?? $data['expires_at']
        ?? $data['current_period_end']
        ?? null;
    $expiresAt = agency_update_parse_subscription_datetime($expiresValue);
    if ($expiresAt !== null && in_array($normalized, ['active', 'trialing'], true) && $expiresAt < new DateTimeImmutable()) {
        return 'expired';
    }
    return $normalized !== '' ? $normalized : 'active';
}

function agency_update_subscription_state($db, string $agencyId): array
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
    $status = agency_update_effective_subscription_status((string)($subscription['status'] ?? $data['subscription_status'] ?? $data['status'] ?? 'active'), $data, $subscription);
    $limit = $subscription['subaccount_limit']
        ?? $subscription['plan_subaccount_limit']
        ?? $data['plan_subaccount_limit']
        ?? $data['subaccount_limit']
        ?? null;
    if ($limit === null || !is_numeric($limit)) {
        $limit = agency_update_plan_limit($plan);
    }

    return [
        'plan' => $plan,
        'status' => $status,
        'limit' => (int)$limit,
    ];
}

function agency_update_active_subaccount_count($db, string $agencyId, string $excludeLocationId): int
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require_once __DIR__ . '/auth_helper.php';
$agencyId = validate_agency_request();
$input = json_decode(file_get_contents('php://input'), true);
$locationId = $input['location_id'] ?? '';
if (!$locationId) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing location_id.']);
    exit;
}
try {
    $db = get_firestore();
    
    // Validate that the location actually belongs to this agency
    $docRef = $db->collection('agency_subaccounts')->document($locationId);
    $snapshot = $docRef->snapshot();
    
    if (!$snapshot->exists() || trim($snapshot->data()['agency_id'] ?? '') !== trim($agencyId)) {
        http_response_code(404);
        echo json_encode(['error' => 'Subaccount not found for this agency.']);
        exit;
    }
    $tokenRef = $db->collection('ghl_tokens')->document($locationId);
    $tokenSnap = $tokenRef->snapshot();
    $tokenData = $tokenSnap->exists() ? $tokenSnap->data() : [];
    
    $toggleEnabled = isset($input['toggle_enabled']) ? (bool)$input['toggle_enabled'] : ($tokenData['toggle_enabled'] ?? true);
    $rateLimit = isset($input['rate_limit']) ? (int)$input['rate_limit'] : ($tokenData['rate_limit'] ?? 0);
    $rateLimitEnabled = array_key_exists('rate_limit', $input)
        ? ($rateLimit > 0)
        : (bool)($tokenData['rate_limit_enabled'] ?? false);
    $resetCounter = isset($input['reset_counter']) ? (bool)$input['reset_counter'] : false;
    
    $activations = (int)($tokenData['toggle_activation_count'] ?? 0);
    $attemptCount = (int)($tokenData['attempt_count'] ?? 0);
    
    $updates = [
        'toggle_enabled' => $toggleEnabled,
        'rate_limit' => $rateLimit,
        'rate_limit_enabled' => $rateLimitEnabled,
        'rate_limit_source' => array_key_exists('rate_limit', $input) ? 'agency_configured' : ($tokenData['rate_limit_source'] ?? 'default_unlimited'),
        'updated_at' => new \Google\Cloud\Core\Timestamp(new \DateTimeImmutable())
    ];
    
    // Enforce 3 max activations for "toggle_enabled"
    if ($toggleEnabled && !($tokenData['toggle_enabled'] ?? false)) {
        $subscriptionState = agency_update_subscription_state($db, trim((string)$agencyId));
        if (in_array($subscriptionState['status'], ['past_due', 'expired', 'inactive'], true)) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Subscription inactive',
                'status' => 'limit_reached',
                'reason' => 'subscription_inactive',
                'subscription_status' => $subscriptionState['status'],
            ]);
            exit;
        }

        $planLimit = (int)$subscriptionState['limit'];
        $activeCount = agency_update_active_subaccount_count($db, trim((string)$agencyId), $locationId);
        if ($planLimit !== -1 && $activeCount >= $planLimit) {
            http_response_code(403);
            echo json_encode([
                'error' => 'Subaccount limit reached',
                'status' => 'limit_reached',
                'reason' => 'subscription_limit',
                'plan' => $subscriptionState['plan'],
                'limit' => $planLimit,
                'active_subaccounts' => $activeCount,
            ]);
            exit;
        }

        if ($activations >= 3) {
            http_response_code(403);
            echo json_encode(['error' => 'Activation Limit Reached', 'status' => 'limit_reached']);
            exit;
        }
        $activations++;
        $updates['toggle_activation_count'] = $activations;
    }
    
    // Reset attempt_count logic
    if ($resetCounter) {
        $attemptCount = 0;
        $updates['attempt_count'] = 0;
    }

    $updates['toggle_activation_count'] = $activations;
    $updates['attempt_count'] = $attemptCount;

    // Apply updates to ghl_tokens first (primary)
    $tokenRef->set($updates, ['merge' => true]);

    // Apply updates to legacy agency_subaccounts
    $docRef->set($updates, ['merge' => true]);

    try {
        require_once __DIR__ . '/../cache_helper.php';
        NolaCache::invalidateAgencyDashboard($agencyId);
    } catch (\Throwable $e) {
        error_log('[update_subaccount] Cache invalidation failed: ' . $e->getMessage());
    }

    echo json_encode([
        'status' => 'success',
        'toggle_enabled' => $toggleEnabled,
        'rate_limit' => $rateLimit,
        'attempt_count' => $attemptCount,
        'toggle_activation_count' => $activations
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Update failed: ' . $e->getMessage()]);
}
