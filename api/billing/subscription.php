<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';

$db = get_firestore();

function billing_subscription_parse_datetime($value): ?DateTimeImmutable
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

function billing_subscription_effective_status(string $status, $expiresValue): string
{
    $normalized = strtolower(trim($status));
    $expiresAt = billing_subscription_parse_datetime($expiresValue);
    if ($expiresAt !== null && in_array($normalized, ['active', 'trialing'], true) && $expiresAt < new DateTimeImmutable()) {
        return 'expired';
    }
    return $normalized !== '' ? $normalized : 'active';
}

function billing_subscription_format_timestamp($value): ?string
{
    if ($value instanceof \Google\Cloud\Core\Timestamp) {
        return $value->get()->format('Y-m-d\TH:i:s\Z');
    }
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d\TH:i:s\Z');
    }
    if (is_string($value) && trim($value) !== '') {
        return $value;
    }

    return null;
}

function billing_subscription_find_agency($db, string $agencyId): array
{
    $agencyId = trim($agencyId);
    if ($agencyId === '') {
        return [null, []];
    }

    try {
        $snap = $db->collection('agency_users')->document($agencyId)->snapshot();
        if ($snap->exists()) {
            return [$snap->id(), $snap->data()];
        }
    } catch (\Throwable $ignored) {
    }

    foreach (['company_id', 'agency_id'] as $field) {
        try {
            $docs = $db->collection('agency_users')
                ->where($field, '=', $agencyId)
                ->limit(1)
                ->documents();

            foreach ($docs as $doc) {
                if ($doc->exists()) {
                    return [$doc->id(), $doc->data()];
                }
            }
        } catch (\Throwable $ignored) {
        }
    }

    try {
        $snap = $db->collection('agency_wallet')->document($agencyId)->snapshot();
        if ($snap->exists()) {
            return [$snap->id(), $snap->data()];
        }
    } catch (\Throwable $ignored) {
    }

    return [null, []];
}

function billing_subscription_plan_limit(string $plan): int
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

function billing_subscription_normalize_plan($value): string
{
    $plan = strtolower(trim((string)$value));
    $aliases = [
        'free' => 'starter',
        'basic' => 'starter',
        'pro' => 'growth',
        'professional' => 'agency',
        'unlimited' => 'enterprise',
    ];
    return $aliases[$plan] ?? (in_array($plan, ['starter', 'growth', 'agency', 'enterprise'], true) ? $plan : 'starter');
}

function billing_subscription_resolve_limit(array $subscription, array $agencyData, string $plan): int
{
    $explicit = $subscription['subaccount_limit']
        ?? $subscription['plan_subaccount_limit']
        ?? $agencyData['plan_subaccount_limit']
        ?? $agencyData['subaccount_limit']
        ?? null;
    if ($explicit !== null && is_numeric($explicit)) {
        return (int)$explicit;
    }

    $legacy = $subscription['max_active_subaccounts'] ?? $agencyData['max_active_subaccounts'] ?? null;
    $hasPlan = isset($subscription['plan']) || isset($agencyData['subscription_plan']) || isset($agencyData['plan']);
    if (!$hasPlan && $legacy !== null && is_numeric($legacy)) {
        return (int)$legacy;
    }

    return billing_subscription_plan_limit($plan);
}

function billing_subscription_count_subaccounts($db, string $agencyId): array
{
    $total = 0;
    $active = 0;
    try {
        $docs = $db->collection('agency_subaccounts')->where('agency_id', '=', $agencyId)->documents();
        foreach ($docs as $doc) {
            if (!$doc->exists()) {
                continue;
            }
            $total++;
            $data = $doc->data();
            if (!array_key_exists('toggle_enabled', $data) || (bool)$data['toggle_enabled']) {
                $active++;
            }
        }
    } catch (\Throwable $e) {
        error_log('[subscription.php] Could not count subaccounts for ' . $agencyId . ': ' . $e->getMessage());
    }
    return [$total, $active];
}

function billing_subscription_plan_catalog(): array
{
    return [
        ['id' => 'starter', 'name' => 'Starter', 'price_monthly' => 0, 'subaccount_limit' => 1],
        ['id' => 'growth', 'name' => 'Growth', 'price_monthly' => 1499, 'subaccount_limit' => 5],
        ['id' => 'agency', 'name' => 'Agency', 'price_monthly' => 3499, 'subaccount_limit' => 25],
        ['id' => 'enterprise', 'name' => 'Enterprise', 'price_monthly' => 7999, 'subaccount_limit' => -1],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $agencyId = trim((string) ($_GET['agency_id'] ?? ''));
    if ($agencyId === '') {
        Logger::error('Missing required param: agency_id', ['endpoint' => 'subscription', 'method' => 'GET']);
        Logger::response(400, ['success' => false, 'error' => 'agency_id is required.']);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'agency_id is required.']);
        exit;
    }

    auth_assert_agency_billing_read_allowed($db, $agencyId);

    $action = trim((string)($_GET['action'] ?? ''));
    if ($action === 'plans') {
        echo json_encode(['success' => true, 'plans' => billing_subscription_plan_catalog()]);
        exit;
    }

    if ($action === 'events') {
        $events = [];
        try {
            $docs = $db->collection('subscription_events')->where('agency_id', '=', $agencyId)->documents();
            foreach ($docs as $doc) {
                if (!$doc->exists()) {
                    continue;
                }
                $event = $doc->data();
                $events[] = [
                    'event_id' => $doc->id(),
                    'event_type' => $event['event_type'] ?? '',
                    'from_plan' => $event['from_plan'] ?? null,
                    'to_plan' => $event['to_plan'] ?? null,
                    'triggered_by' => $event['triggered_by'] ?? null,
                    'ghl_order_id' => $event['ghl_order_id'] ?? null,
                    'created_at' => billing_subscription_format_timestamp($event['created_at'] ?? null),
                ];
            }
            usort($events, fn($a, $b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
        } catch (\Throwable $e) {
            error_log('[subscription.php] Could not load subscription events for ' . $agencyId . ': ' . $e->getMessage());
        }
        echo json_encode(['success' => true, 'events' => $events]);
        exit;
    }

    [$agencyDocId, $agencyData] = billing_subscription_find_agency($db, $agencyId);

    $subscription = $agencyData['subscription'] ?? [];
    if (!is_array($subscription)) {
        $subscription = [];
    }

    $plan = billing_subscription_normalize_plan(
        $subscription['plan']
        ?? $agencyData['subscription_plan']
        ?? $agencyData['plan']
        ?? 'starter'
    );

    $expiresRaw = $subscription['expires_at']
        ?? $subscription['subscription_expires_at']
        ?? $subscription['current_period_end']
        ?? $agencyData['subscription_expires_at']
        ?? $agencyData['expires_at']
        ?? $agencyData['current_period_end']
        ?? null;

    $status = billing_subscription_effective_status((string) (
        $subscription['status']
        ?? $agencyData['subscription_status']
        ?? $agencyData['status']
        ?? 'active'
    ), $expiresRaw);

    $billingCycle = (string) (
        $subscription['billing_cycle']
        ?? $agencyData['billing_cycle']
        ?? 'monthly'
    );

    $maxActiveSubaccounts = billing_subscription_resolve_limit($subscription, $agencyData, $plan);
    [$totalSubaccounts, $activeSubaccounts] = billing_subscription_count_subaccounts($db, $agencyId);
    $expiresAt = billing_subscription_format_timestamp($expiresRaw);

    $payload = [
        'success' => true,
        'agency_id' => $agencyId,
        'agency_doc_id' => $agencyDocId,
        'plan' => $plan,
        'status' => $status,
        'billing_cycle' => $billingCycle,
        'subaccount_limit' => $maxActiveSubaccounts,
        'plan_subaccount_limit' => $maxActiveSubaccounts,
        'max_active_subaccounts' => $maxActiveSubaccounts,
        'subaccounts_used' => $activeSubaccounts,
        'active_subaccounts' => $activeSubaccounts,
        'total_subaccounts' => $totalSubaccounts,
        'expires_at' => $expiresAt,
        'current_period_start' => billing_subscription_format_timestamp(
            $subscription['current_period_start'] ?? $agencyData['current_period_start'] ?? null
        ),
        'current_period_end' => $expiresAt,
        'trial_ends_at' => billing_subscription_format_timestamp(
            $subscription['trial_ends_at'] ?? $agencyData['trial_ends_at'] ?? null
        ),
        'cancel_at_period_end' => (bool) (
            $subscription['cancel_at_period_end']
            ?? $agencyData['cancel_at_period_end']
            ?? false
        ),
        'subscription' => [
            'plan' => $plan,
            'status' => $status,
            'billing_cycle' => $billingCycle,
            'subaccount_limit' => $maxActiveSubaccounts,
            'plan_subaccount_limit' => $maxActiveSubaccounts,
            'max_active_subaccounts' => $maxActiveSubaccounts,
            'subaccounts_used' => $activeSubaccounts,
            'active_subaccounts' => $activeSubaccounts,
            'total_subaccounts' => $totalSubaccounts,
            'expires_at' => $expiresAt,
            'current_period_start' => billing_subscription_format_timestamp(
                $subscription['current_period_start'] ?? $agencyData['current_period_start'] ?? null
            ),
            'current_period_end' => $expiresAt,
            'trial_ends_at' => billing_subscription_format_timestamp(
                $subscription['trial_ends_at'] ?? $agencyData['trial_ends_at'] ?? null
            ),
            'cancel_at_period_end' => (bool) (
                $subscription['cancel_at_period_end']
                ?? $agencyData['cancel_at_period_end']
                ?? false
            ),
        ],
        'limits' => [
            'subaccount_limit' => $maxActiveSubaccounts,
            'plan_subaccount_limit' => $maxActiveSubaccounts,
            'max_active_subaccounts' => $maxActiveSubaccounts,
        ],
    ];

    Logger::response(200, ['success' => true, 'plan' => $plan, 'status' => $status]);
    echo json_encode($payload);
    exit;
}

Logger::error('Method not allowed', ['allowed' => 'GET', 'received' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN']);
Logger::response(405, ['success' => false, 'error' => 'Method not allowed']);
http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
