<?php
/**
 * debug_semaphore_connection.php
 * Pinpoints EXACTLY which TCP/TLS phase times out when connecting to Semaphore.
 *
 * Run via CLI:
 *   php tmp/debug_semaphore_connection.php
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

$SEMAPHORE_API_KEY = getenv('SEMAPHORE_API_KEY') ?: '8089fc9919bc05855ae0d354011f8e4b';

// ─────────────────────────────────────────────────────────────────────────────
function measureConnection(string $label, string $url, string $method = 'GET', array $postFields = [], int $connectTimeout = 8): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_CONNECTTIMEOUT  => $connectTimeout,
        CURLOPT_TIMEOUT         => $connectTimeout + 5,
        CURLOPT_IPRESOLVE       => CURL_IPRESOLVE_V4,
        CURLOPT_FOLLOWLOCATION  => false,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
    ]);

    if ($method === 'POST' && !empty($postFields)) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    }

    $startTime = microtime(true);
    $response  = curl_exec($ch);
    $totalMs   = round((microtime(true) - $startTime) * 1000, 1);

    $info      = curl_getinfo($ch);
    $curlError = curl_error($ch);
    $curlErrNo = curl_errno($ch);
    curl_close($ch);

    // Phase timings (ms)
    $dnMs    = round($info['namelookup_time'] * 1000, 1);
    $tcpMs   = round(($info['connect_time']       - $info['namelookup_time']) * 1000, 1);
    $tlsMs   = round(($info['appconnect_time']    - $info['connect_time'])    * 1000, 1);
    $byteMs  = round(($info['starttransfer_time'] - $info['appconnect_time']) * 1000, 1);

    // Detect failure phase
    $failPhase = null;
    if ($curlErrNo !== 0) {
        if ($info['connect_time'] == 0) {
            $failPhase = 'DNS — could not resolve hostname';
        } elseif ($info['appconnect_time'] == 0) {
            $failPhase = 'TCP CONNECT — SYN sent but no SYN-ACK received (packet DROPPED at Cloudflare edge)';
        } elseif ($info['starttransfer_time'] == 0) {
            $failPhase = 'TLS HANDSHAKE — TCP connected but SSL failed';
        } else {
            $failPhase = 'HTTP READ — connection OK but server gave no response';
        }
    }

    return [
        'label'       => $label,
        'url'         => $url,
        'http_code'   => $info['http_code'],
        'ip'          => $info['primary_ip'] ?? '?',
        'curl_error'  => $curlError ?: null,
        'curl_errno'  => $curlErrNo,
        'fail_phase'  => $failPhase,
        'dns_ms'      => $dnMs,
        'tcp_ms'      => $tcpMs,
        'tls_ms'      => $tlsMs,
        'byte_ms'     => $byteMs,
        'total_ms'    => $totalMs,
        'response'    => $response ? substr($response, 0, 150) : null,
        'failed'      => ($curlErrNo !== 0),
    ];
}

function printResult(array $r): void
{
    $ok = $r['failed'] ? '❌ FAILED' : '✅ OK';
    echo "  [$ok] {$r['label']}\n";
    echo "         IP resolved : {$r['ip']}\n";
    echo "         HTTP code   : {$r['http_code']}\n";
    echo "         DNS         : {$r['dns_ms']} ms\n";
    echo "         TCP connect : {$r['tcp_ms']} ms\n";
    echo "         TLS shake   : {$r['tls_ms']} ms\n";
    echo "         First byte  : {$r['byte_ms']} ms\n";
    echo "         TOTAL       : {$r['total_ms']} ms\n";
    if ($r['fail_phase']) {
        echo "  ⚠️  FAILURE PHASE → {$r['fail_phase']}\n";
        echo "         cURL error  : {$r['curl_error']} (errno {$r['curl_errno']})\n";
    } elseif ($r['response']) {
        echo "         Response    : " . substr($r['response'], 0, 100) . "\n";
    }
    echo "\n";
}

// ─────────────────────────────────────────────────────────────────────────────
echo "========================================================================\n";
echo " NOLA SMS Pro — TCP/TLS Connection Phase Debugger\n";
echo " Time     : " . date('Y-m-d H:i:s T') . "\n";
echo " PHP      : " . PHP_VERSION . "\n";
echo "========================================================================\n\n";

$rounds  = 3;
$allResults = [];

for ($round = 1; $round <= $rounds; $round++) {
    echo "───── ROUND $round of $rounds ─────────────────────────────────────────────────\n\n";

    // 1. Semaphore account API — safe read-only call, no SMS sent
    $r1 = measureConnection(
        'Semaphore /account (read-only)',
        "https://api.semaphore.co/api/v4/account?apikey={$SEMAPHORE_API_KEY}"
    );
    printResult($r1);
    $allResults[] = $r1;
    sleep(1);

    // 2. Semaphore messages endpoint — TCP probe only (will get 401, that's fine)
    $r2 = measureConnection(
        'Semaphore /messages endpoint (TCP probe)',
        'https://api.semaphore.co/api/v4/messages'
    );
    printResult($r2);
    $allResults[] = $r2;
    sleep(1);

    // 3. UniSMS — baseline comparison
    $r3 = measureConnection(
        'UniSMS API (baseline)',
        'https://unismsapi.com/api'
    );
    printResult($r3);
    $allResults[] = $r3;
    sleep(1);

    // 4. Google APIs — proves OUR internet works
    $r4 = measureConnection(
        'Google APIs (internet health check)',
        'https://www.googleapis.com/discovery/v1/apis'
    );
    printResult($r4);
    $allResults[] = $r4;

    if ($round < $rounds) {
        echo "  [Waiting 2s before next round...]\n\n";
        sleep(2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
echo "\n========================================================================\n";
echo " FINAL VERDICT\n";
echo "========================================================================\n\n";

$semFails  = count(array_filter($allResults, fn($r) => $r['failed'] && str_contains($r['label'], 'Semaphore')));
$uniFails  = count(array_filter($allResults, fn($r) => $r['failed'] && str_contains($r['label'], 'UniSMS')));
$gFails    = count(array_filter($allResults, fn($r) => $r['failed'] && str_contains($r['label'], 'Google')));
$semTotal  = count(array_filter($allResults, fn($r) => str_contains($r['label'], 'Semaphore')));

echo "  Semaphore failures : {$semFails} / {$semTotal}\n";
echo "  UniSMS failures    : {$uniFails} / " . count(array_filter($allResults, fn($r) => str_contains($r['label'], 'UniSMS'))) . "\n";
echo "  Google failures    : {$gFails} / " . count(array_filter($allResults, fn($r) => str_contains($r['label'], 'Google'))) . "\n\n";

echo "  TCP Connect Times (Semaphore):\n";
foreach ($allResults as $r) {
    if (!str_contains($r['label'], 'Semaphore')) continue;
    $status = $r['failed'] ? "FAILED ({$r['fail_phase']})" : "OK";
    echo "    Round: tcp={$r['tcp_ms']}ms tls={$r['tls_ms']}ms total={$r['total_ms']}ms → {$status}\n";
}

echo "\n  DIAGNOSIS:\n";
if ($semFails > 0 && $uniFails === 0 && $gFails === 0) {
    echo "  ✅ CONFIRMED → Issue is 100% on Semaphore's side.\n";
    echo "     Our server connects fine to UniSMS and Google.\n";
    echo "     Only Semaphore TCP connections are being dropped.\n";
    $failedOnes = array_filter($allResults, fn($r) => $r['failed'] && str_contains($r['label'], 'Semaphore'));
    foreach ($failedOnes as $r) {
        echo "     Failure phase: {$r['fail_phase']}\n";
    }
} elseif ($semFails === 0) {
    echo "  ✅ All connections to Semaphore succeeded in this run.\n";
    echo "     Issue is intermittent. Check Cloud Logging for historical patterns.\n";
    echo "     Run this script multiple times or during peak hours to catch it live.\n";
} elseif ($uniFails > 0 || $gFails > 0) {
    echo "  ⚠️  Multiple providers failing — possible OUR server network issue.\n";
    echo "     Investigate Cloud Run egress or VPC connector configuration.\n";
}

echo "\n  WHAT TCP PHASES TELL US:\n";
echo "  • TCP connect = 0ms, curl error → DNS failed (name not resolving)\n";
echo "  • TCP connect = 5000ms+, TLS = 0ms → TCP SYN DROPPED (Cloudflare blocked us)\n";
echo "  • TCP connect = OK, TLS timeout → TLS cert/config issue\n";
echo "  • TCP+TLS = OK, first byte timeout → Server accepted but didn't respond\n";
echo "\n";
