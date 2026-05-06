<?php
// Temporary debug reader — shows last GHL install callback data
// Usage: https://smspro-api.nolacrm.io/tmp_inspect.php?secret=nola_debug_2026
$secret = $_GET['secret'] ?? '';
if ($secret !== 'nola_debug_2026') { http_response_code(403); echo 'Forbidden'; exit; }
header('Content-Type: application/json');
$file = '/tmp/ghl_debug_install.json';
if (file_exists($file)) {
    echo file_get_contents($file);
} else {
    echo json_encode(['error' => 'No debug data yet. Trigger the install flow first.']);
}
