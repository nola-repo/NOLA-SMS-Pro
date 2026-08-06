<?php
/**
 * NOLA SMS PRO — Network Connectivity Diagnostic Tool
 * =====================================================
 * Run this script from a browser or CLI to test outbound connectivity
 * from YOUR SERVER to Semaphore and UniSMS APIs.
 *
 * Usage (CLI):  php tmp/network_diagnostics.php
 * Usage (browser): https://your-server/tmp/network_diagnostics.php
 *
 * Share the output with your hosting provider as evidence of intermittent
 * connection timeouts to external APIs.
 */

// ── Safety guard ─────────────────────────────────────────────────────────────
// Remove or change this password before exposing via browser.
$ACCESS_KEY = 'nola_diag_2026';
if (PHP_SAPI !== 'cli') {
    $provided = $_GET['key'] ?? $_SERVER['HTTP_X_DIAG_KEY'] ?? '';
    if ($provided !== $ACCESS_KEY) {
        http_response_code(403);
        die(json_encode(['error' => 'Forbidden — pass ?key=nola_diag_2026']));
    }
    header('Content-Type: application/json; charset=utf-8');
}

$report = [
    'generated_at'   => date('c'),
    'server_hostname' => gethostname() ?: 'unknown',
    'server_ip'      => gethostbyname(gethostname() ?: 'localhost'),
    'php_version'    => PHP_VERSION,
    'tests'          => [],
    'summary'        => [],
];

// ── Targets ───────────────────────────────────────────────────────────────────
$targets = [
    [
        'label'   => 'Semaphore API — Account endpoint',
        'url'     => 'https://api.semaphore.co/api/v4/account?apikey=test_connectivity_probe',
        'method'  => 'GET',
        'payload' => null,
    ],
    [
        'label'   => 'Semaphore API — Messages endpoint (OPTIONS)',
        'url'     => 'https://api.semaphore.co/api/v4/messages',
        'method'  => 'GET',
        'payload' => null,
    ],
    [
        'label'   => 'UniSMS API — Account endpoint',
        'url'     => 'https://unismsapi.com/api/account',
        'method'  => 'GET',
        'payload' => null,
    ],
    [
        'label'   => 'UniSMS API — SMS endpoint (POST probe)',
        'url'     => 'https://unismsapi.com/api/sms',
        'method'  => 'POST',
        'payload' => ['recipient' => '+639000000000', 'content' => 'probe', 'sender_id' => 'TEST'],
    ],
    [
        'label'   => 'DNS Check — Semaphore host',
        'url'     => null,
        'host'    => 'api.semaphore.co',
        'method'  => 'DNS',
        'payload' => null,
    ],
    [
        'label'   => 'DNS Check — UniSMS host',
        'url'     => null,
        'host'    => 'unismsapi.com',
        'method'  => 'DNS',
        'payload' => null,
    ],
    [
        'label'   => 'TCP Connect — Semaphore :443',
        'url'     => null,
        'host'    => 'api.semaphore.co',
        'port'    => 443,
        'method'  => 'TCP',
        'payload' => null,
    ],
    [
        'label'   => 'TCP Connect — UniSMS :443',
        'url'     => null,
        'host'    => 'unismsapi.com',
        'port'    => 443,
        'method'  => 'TCP',
        'payload' => null,
    ],
];

// ── Run tests ─────────────────────────────────────────────────────────────────
$totalFailed  = 0;
$totalTimeout = 0;

foreach ($targets as $target) {
    $result = [
        'label'   => $target['label'],
        'method'  => $target['method'],
        'url'     => $target['url'] ?? ($target['host'] ?? ''),
        'status'  => 'unknown',
        'details' => [],
    ];

    // ── DNS ────────────────────────────────────────────────────────────────────
    if ($target['method'] === 'DNS') {
        $host = $target['host'];
        $t0   = microtime(true);
        $ip   = @gethostbyname($host);
        $ms   = round((microtime(true) - $t0) * 1000, 2);

        $resolved = ($ip !== $host);
        $result['status']       = $resolved ? 'ok' : 'failed';
        $result['details']      = [
            'resolved_ip'  => $resolved ? $ip : null,
            'duration_ms'  => $ms,
            'dns_ok'       => $resolved,
        ];
        if (!$resolved) {
            $totalFailed++;
            $result['details']['error'] = "DNS could not resolve $host";
        }

    // ── TCP ────────────────────────────────────────────────────────────────────
    } elseif ($target['method'] === 'TCP') {
        $host = $target['host'];
        $port = $target['port'] ?? 443;
        $t0   = microtime(true);
        $sock = @fsockopen("ssl://{$host}", $port, $errno, $errstr, 8);
        $ms   = round((microtime(true) - $t0) * 1000, 2);

        $ok = ($sock !== false);
        if ($sock) {
            fclose($sock);
        }
        $result['status']  = $ok ? 'ok' : 'failed';
        $result['details'] = [
            'host'        => $host,
            'port'        => $port,
            'duration_ms' => $ms,
            'connected'   => $ok,
        ];
        if (!$ok) {
            $totalFailed++;
            $result['details']['error'] = "TCP connect failed ($errno): $errstr";
            if ($ms >= 8000) {
                $totalTimeout++;
                $result['status'] = 'timeout';
            }
        }

    // ── HTTP (cURL) ────────────────────────────────────────────────────────────
    } else {
        $RUNS = 3; // run each HTTP probe 3× to capture intermittent issues
        $runs = [];

        for ($i = 1; $i <= $RUNS; $i++) {
            $ch = curl_init($target['url']);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_VERBOSE, false);

            if ($target['method'] === 'POST' && $target['payload']) {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($target['payload']));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]);
            }

            $t0        = microtime(true);
            $response  = curl_exec($ch);
            $elapsed   = round((microtime(true) - $t0) * 1000, 2);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr   = curl_error($ch);
            $connTime  = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 2);
            $namelook  = round(curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME) * 1000, 2);
            curl_close($ch);

            $isTimeout = ($response === false && (
                str_contains($curlErr, 'timed out') ||
                str_contains($curlErr, 'Connection timed') ||
                str_contains($curlErr, 'Operation timed')
            ));

            $run = [
                'run'              => $i,
                'http_code'        => $httpCode,
                'total_ms'         => $elapsed,
                'connect_ms'       => $connTime,
                'dns_ms'           => $namelook,
                'ok'               => ($response !== false && $httpCode > 0),
                'is_timeout'       => $isTimeout,
                'curl_error'       => $curlErr ?: null,
                'response_preview' => $response !== false
                    ? substr($response, 0, 120)
                    : null,
            ];

            if ($isTimeout) {
                $totalTimeout++;
            }
            if ($response === false || $httpCode === 0) {
                $totalFailed++;
            }

            $runs[] = $run;

            // Small gap between probe runs so we don't hammer the API
            if ($i < $RUNS) {
                usleep(300000); // 300 ms
            }
        }

        $okCount   = count(array_filter($runs, fn($r) => $r['ok']));
        $avgMs     = round(array_sum(array_column($runs, 'total_ms')) / $RUNS, 2);

        $result['status']  = $okCount === $RUNS ? 'ok' : ($okCount > 0 ? 'intermittent' : 'failed');
        $result['details'] = [
            'runs_ok'     => $okCount,
            'runs_total'  => $RUNS,
            'avg_ms'      => $avgMs,
            'runs'        => $runs,
        ];
    }

    $report['tests'][] = $result;
}

// ── Summary ───────────────────────────────────────────────────────────────────
$allOk       = ($totalFailed === 0 && $totalTimeout === 0);
$anyTimeout  = $totalTimeout > 0;
$anyFailed   = $totalFailed > 0;

$verdict = 'All connectivity checks passed.';
if ($anyTimeout) {
    $verdict = 'TIMEOUT detected — your server is having trouble reaching the SMS provider APIs. '
        . 'This is a network or firewall issue on your hosting side.';
} elseif ($anyFailed) {
    $verdict = 'CONNECTIVITY FAILURES detected — some requests could not reach the provider APIs.';
}

$report['summary'] = [
    'overall_status'   => $allOk ? 'healthy' : ($anyTimeout ? 'timeout' : 'degraded'),
    'total_tests'      => count($report['tests']),
    'total_failures'   => $totalFailed,
    'total_timeouts'   => $totalTimeout,
    'verdict'          => $verdict,
    'recommendation'   => $anyTimeout || $anyFailed
        ? 'Share this output with your hosting provider. Ask them to: '
            . '(1) check outbound firewall rules for port 443 to api.semaphore.co and unismsapi.com, '
            . '(2) verify DNS resolver stability, '
            . '(3) check for connection pool exhaustion or resource limits.'
        : 'No action needed at this time.',
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
