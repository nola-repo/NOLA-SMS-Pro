<?php
require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

require_once __DIR__ . '/../webhook/firestore_client.php';
require_once __DIR__ . '/auth_helper.php';
require_once __DIR__ . '/../install_helpers.php';

$agencyId = validate_agency_request(true);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$companyId = trim((string)($_GET['company_id'] ?? $agencyId));
if ($companyId === '') {
    http_response_code(422);
    echo json_encode(['error' => 'company_id is required']);
    exit;
}

try {
    $db = get_firestore();
    $docs = $db->collection('ghl_tokens')->where('companyId', '=', $companyId)->documents();
    $locations = [];
    foreach ($docs as $doc) {
        if (!$doc->exists()) {
            continue;
        }
        $d = $doc->data();
        $isCompanyToken = ($d['userType'] ?? '') === 'Company' || (string)$doc->id() === $companyId;
        if ($isCompanyToken) {
            continue;
        }
        if (($d['install_state'] ?? '') === INSTALL_STATE_PENDING_OAUTH) {
            continue;
        }
        if (!install_token_active_for_sms(true, $d)) {
            continue;
        }
        $locId = (string)($d['location_id'] ?? $doc->id());
        if ($locId === '' || $locId === $companyId) {
            continue;
        }
        $locations[] = [
            'location_id' => $locId,
            'location_name' => (string)($d['location_name'] ?? ''),
            'company_id' => $companyId,
        ];
    }

    usort($locations, static function (array $a, array $b): int {
        return strcmp(strtolower($a['location_name']), strtolower($b['location_name']));
    });

    echo json_encode(['locations' => $locations]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load locations']);
}
