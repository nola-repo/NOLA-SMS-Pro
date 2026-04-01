<?php

/**
 * Public Whitelabel Endpoint
 * 
 * Returns branding data (logo, color, company name) for a given custom domain.
 * This endpoint is PUBLIC — no authentication required.
 *
 * Usage: GET /api/public/whitelabel?domain=app.agencydomain.com
 */

require_once __DIR__ . '/../cors.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

$domain = trim($_GET['domain'] ?? '');

if (empty($domain)) {
    // Return defaults when no domain specified
    echo json_encode([
        'status' => 'success',
        'branding' => [
            'company_name' => 'NOLA SMS Pro',
            'logo_url' => '',
            'primary_color' => '#2b83fa',
            'agency_id' => null,
        ]
    ]);
    exit;
}

// Sanitize domain — only allow valid hostname characters
$domain = strtolower(preg_replace('/[^a-z0-9.\-]/', '', $domain));

require_once __DIR__ . '/../webhook/firestore_client.php';
$db = get_firestore();

try {
    $docRef = $db->collection('whitelabel_domains')->document($domain);
    $snapshot = $docRef->snapshot();

    if (!$snapshot->exists()) {
        // Domain not found — return defaults
        echo json_encode([
            'status' => 'success',
            'branding' => [
                'company_name' => 'NOLA SMS Pro',
                'logo_url' => '',
                'primary_color' => '#2b83fa',
                'agency_id' => null,
            ]
        ]);
        exit;
    }

    $data = $snapshot->data();

    echo json_encode([
        'status' => 'success',
        'branding' => [
            'company_name' => $data['company_name'] ?? 'NOLA SMS Pro',
            'logo_url'     => $data['logo_url'] ?? '',
            'primary_color'=> $data['primary_color'] ?? '#2b83fa',
            'agency_id'    => $data['agency_id'] ?? null,
        ]
    ]);

} catch (Exception $e) {
    error_log('Whitelabel endpoint error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Internal server error']);
}
