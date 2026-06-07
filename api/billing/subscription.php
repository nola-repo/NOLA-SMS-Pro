<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';

// Authentication - accepts X-Webhook-Secret header (frontend billing requests)
validate_api_request();

$db = get_firestore();

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

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $agencyId = trim((string) ($_GET['agency_id'] ?? ''));
    if ($agencyId === '') {
        Logger::error('Missing required param: agency_id', ['endpoint' => 'subscription', 'method' => 'GET']);
        Logger::response(400, ['success' => false, 'error' => 'agency_id is required.']);
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'agency_id is required.']);
        exit;
    }

    [$agencyDocId, $agencyData] = billing_subscription_find_agency($db, $agencyId);

    $subscription = $agencyData['subscription'] ?? [];
    if (!is_array($subscription)) {
        $subscription = [];
    }

    $plan = (string) (
        $subscription['plan']
        ?? $agencyData['subscription_plan']
        ?? $agencyData['plan']
        ?? 'agency'
    );

    $status = (string) (
        $subscription['status']
        ?? $agencyData['subscription_status']
        ?? $agencyData['status']
        ?? 'active'
    );

    $billingCycle = (string) (
        $subscription['billing_cycle']
        ?? $agencyData['billing_cycle']
        ?? 'monthly'
    );

    $maxActiveSubaccounts = (int) (
        $subscription['max_active_subaccounts']
        ?? $agencyData['max_active_subaccounts']
        ?? 3
    );

    $payload = [
        'success' => true,
        'agency_id' => $agencyId,
        'agency_doc_id' => $agencyDocId,
        'plan' => $plan,
        'status' => $status,
        'billing_cycle' => $billingCycle,
        'max_active_subaccounts' => $maxActiveSubaccounts,
        'current_period_start' => billing_subscription_format_timestamp(
            $subscription['current_period_start'] ?? $agencyData['current_period_start'] ?? null
        ),
        'current_period_end' => billing_subscription_format_timestamp(
            $subscription['current_period_end'] ?? $agencyData['current_period_end'] ?? null
        ),
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
            'max_active_subaccounts' => $maxActiveSubaccounts,
            'current_period_start' => billing_subscription_format_timestamp(
                $subscription['current_period_start'] ?? $agencyData['current_period_start'] ?? null
            ),
            'current_period_end' => billing_subscription_format_timestamp(
                $subscription['current_period_end'] ?? $agencyData['current_period_end'] ?? null
            ),
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
