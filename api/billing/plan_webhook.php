<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/../auth_helpers.php';
require_once __DIR__ . '/../cache_helper.php';
require_once __DIR__ . '/../services/ReferenceId.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

validate_api_request();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}

$agencyId = trim((string)($input['agency_id'] ?? $input['company_id'] ?? ''));
$plan = strtolower(trim((string)($input['plan'] ?? '')));
$orderId = trim((string)($input['order_id'] ?? $input['ghl_order_id'] ?? ''));
$action = strtolower(trim((string)($input['action'] ?? $input['event_type'] ?? $input['status'] ?? '')));

$plans = [
    'starter' => ['rank' => 0, 'limit' => 1],
    'growth' => ['rank' => 1, 'limit' => 5],
    'agency' => ['rank' => 2, 'limit' => 25],
    'enterprise' => ['rank' => 3, 'limit' => -1],
];

if ($agencyId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'agency_id is required']);
    exit;
}

$db = get_firestore();
$agencyRef = null;
$agencyDocId = null;
$agencyData = [];

foreach (['company_id', 'agency_id'] as $field) {
    $docs = $db->collection('agency_users')->where($field, '=', $agencyId)->limit(1)->documents();
    foreach ($docs as $doc) {
        if ($doc->exists()) {
            $agencyRef = $doc->reference();
            $agencyDocId = $doc->id();
            $agencyData = $doc->data();
            break 2;
        }
    }
}

if ($agencyRef === null) {
    $snap = $db->collection('agency_users')->document($agencyId)->snapshot();
    if ($snap->exists()) {
        $agencyRef = $snap->reference();
        $agencyDocId = $snap->id();
        $agencyData = $snap->data();
    }
}

if ($agencyRef === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Agency not found']);
    exit;
}

$now = new \DateTimeImmutable();
$nowTs = new \Google\Cloud\Core\Timestamp($now);

// ── Handle Payment Failure / Account Suspension / Cancellation ───────────────
if (in_array($action, ['payment_failed', 'past_due', 'unpaid', 'canceled', 'cancelled', 'paused', 'suspend', 'suspended'], true)) {
    $newStatus = in_array($action, ['canceled', 'cancelled'], true) ? 'canceled' : 'past_due';
    $autoPauseSubaccounts = (bool)($input['pause_subaccounts'] ?? true);

    $agencyRef->set([
        'subscription_status' => $newStatus,
        'updated_at' => $nowTs,
        'subscription' => array_merge($agencyData['subscription'] ?? [], [
            'status' => $newStatus,
            'updated_at' => $nowTs,
        ]),
    ], ['merge' => true]);

    $pausedCount = 0;
    if ($autoPauseSubaccounts) {
        try {
            $subDocs = $db->collection('agency_subaccounts')->where('agency_id', '=', $agencyId)->documents();
            foreach ($subDocs as $subDoc) {
                if (!$subDoc->exists()) continue;
                $subDoc->reference()->set([
                    'subscription_status' => $newStatus,
                    'toggle_enabled' => false,
                    'updated_at' => $nowTs,
                ], ['merge' => true]);

                $tokRef = $db->collection('ghl_tokens')->document($subDoc->id());
                if ($tokRef->snapshot()->exists()) {
                    $tokRef->set([
                        'subscription_status' => $newStatus,
                        'toggle_enabled' => false,
                        'updated_at' => $nowTs,
                    ], ['merge' => true]);
                }
                $pausedCount++;
            }
        } catch (\Throwable $e) {
            error_log('[plan_webhook] Failed to pause subaccounts for ' . $agencyId . ': ' . $e->getMessage());
        }
    }

    $eventRef = $db->collection('subscription_events')->newDocument();
    $eventReferenceId = ReferenceId::generate('SUB');
    $eventRef->set([
        'agency_id' => $agencyId,
        'event_id' => $eventRef->id(),
        'reference_id' => $eventReferenceId,
        'event_reference_id' => $eventReferenceId,
        'user_id' => $agencyDocId,
        'event_type' => $action,
        'to_status' => $newStatus,
        'triggered_by' => 'ghl_webhook',
        'ghl_order_id' => $orderId !== '' ? $orderId : null,
        'created_at' => $nowTs,
    ]);

    NolaCache::invalidateAgencyDashboard($agencyId);

    echo json_encode([
        'success' => true,
        'status' => $newStatus,
        'paused_subaccounts' => $pausedCount,
        'reference_id' => $eventReferenceId,
    ]);
    exit;
}

// ── Handle Subscription / Plan Activation & Renewal ─────────────────────────
if ($plan === '' || !isset($plans[$plan])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid subscription plan is required (starter, growth, agency, enterprise)']);
    exit;
}

$currentPlan = strtolower(trim((string)($agencyData['subscription_plan'] ?? $agencyData['subscription']['plan'] ?? 'starter')));
$eventType = 'renewed';
if ($currentPlan === '' || $currentPlan === 'starter') {
    $eventType = $plan === $currentPlan ? 'renewed' : 'subscribed';
} elseif (($plans[$plan]['rank'] ?? 0) > ($plans[$currentPlan]['rank'] ?? 0)) {
    $eventType = 'upgraded';
}

$expiresAt = $now->modify('+1 month');
$expiresTs = new \Google\Cloud\Core\Timestamp($expiresAt);
$limit = $plans[$plan]['limit'];

$agencyRef->set([
    'subscription_plan' => $plan,
    'subscription_status' => 'active',
    'plan_subaccount_limit' => $limit,
    'subaccount_limit' => $limit,
    'max_active_subaccounts' => $limit,
    'subscription_started_at' => $nowTs,
    'current_period_start' => $nowTs,
    'subscription_expires_at' => $expiresTs,
    'expires_at' => $expiresTs,
    'current_period_end' => $expiresTs,
    'updated_at' => $nowTs,
    'subscription' => [
        'plan' => $plan,
        'status' => 'active',
        'subaccount_limit' => $limit,
        'plan_subaccount_limit' => $limit,
        'max_active_subaccounts' => $limit,
        'current_period_start' => $nowTs,
        'current_period_end' => $expiresTs,
        'expires_at' => $expiresTs,
    ],
], ['merge' => true]);

$eventRef = $db->collection('subscription_events')->newDocument();
$eventReferenceId = ReferenceId::generate('SUB');
$eventRef->set([
    'agency_id' => $agencyId,
    'event_id' => $eventRef->id(),
    'reference_id' => $eventReferenceId,
    'event_reference_id' => $eventReferenceId,
    'user_id' => $agencyDocId,
    'event_type' => $eventType,
    'from_plan' => $currentPlan ?: null,
    'to_plan' => $plan,
    'triggered_by' => 'ghl_webhook',
    'ghl_order_id' => $orderId !== '' ? $orderId : null,
    'created_at' => $nowTs,
]);

NolaCache::invalidateAgencyDashboard($agencyId);

echo json_encode([
    'success' => true,
    'plan' => $plan,
    'status' => 'active',
    'subaccount_limit' => $limit,
    'expires_at' => $expiresAt->format('Y-m-d\TH:i:s\Z'),
    'reference_id' => $eventReferenceId,
    'event_reference_id' => $eventReferenceId,
]);
