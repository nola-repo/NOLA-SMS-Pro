<?php
require_once __DIR__ . '/../api/services/StatusSync.php';

use Nola\Services\StatusSync;

function test($curr, $new, $expected) {
    $ref = new ReflectionMethod(StatusSync::class, 'isUpgrade');
    $ref->setAccessible(true);
    $result = $ref->invoke(null, $curr, $new);
    $status = ($result === $expected) ? "PASS" : "FAIL";
    echo "[$status] $curr -> $new (Expected: " . ($expected ? 'true' : 'false') . ", Got: " . ($result ? 'true' : 'false') . ")\n";
}

echo "Testing SMS Status Upgrade Logic:\n";
test('Pending', 'Sending', true);  // Legacy cleanup
test('Queued', 'Sending', true);   // Legacy cleanup
test('Sending', 'Sent', true);     // Normal path
test('Sending', 'Failed', true);   // Normal path
test('Sent', 'Sending', false);    // No downgrade
test('Failed', 'Sending', false);  // No downgrade
test('Sent', 'Failed', false);     // Final is final
test('Sending', 'Sending', false); // No redundant update
echo "\nVerification Complete.\n";
