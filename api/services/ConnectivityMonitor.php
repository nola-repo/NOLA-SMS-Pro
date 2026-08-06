<?php

/**
 * ConnectivityMonitor
 * ====================
 * Automatically runs network diagnostics when an SMS provider fails after
 * all retry attempts are exhausted, or on a scheduled health-check basis.
 *
 * Results are stored in Firestore → "connectivity_incidents" collection
 * so you have a timestamped history of every network issue.
 *
 * Triggered by:
 *   - SemaphoreProvider / UniSmsProvider after final retry failure
 *   - Scheduled health check (e.g. every 30 minutes via cron endpoint)
 */
class ConnectivityMonitor
{
    private const CONNECT_TIMEOUT = 8;   // seconds
    private const HTTP_TIMEOUT    = 10;  // seconds
    private const HTTP_PROBE_RUNS = 2;   // how many times to probe each HTTP endpoint

    /**
     * Run all diagnostics and save to Firestore.
     * Called automatically when a provider exhausts all retries.
     *
     * @param  string      $triggerProvider  'semaphore' | 'unisms'
     * @param  string      $triggerError     The final error message that caused the trigger
     * @param  string      $triggerContext   'send' | 'checkStatus' | 'checkAccount' | 'scheduled'
     * @param  mixed|null  $db               Firestore client (optional; will init if null)
     * @return array       The full diagnostic report
     */
    public static function runAndSave(
        string $triggerProvider = 'unknown',
        string $triggerError    = '',
        string $triggerContext  = 'send',
        $db = null
    ): array {
        $startedAt = microtime(true);
        $report    = self::runDiagnostics();

        $report['trigger'] = [
            'provider' => $triggerProvider,
            'context'  => $triggerContext,
            'error'    => $triggerError,
        ];

        $report['duration_ms'] = round((microtime(true) - $startedAt) * 1000, 2);

        // Save to Firestore asynchronously — never let this block the main SMS flow
        try {
            if ($db === null) {
                require_once __DIR__ . '/../webhook/firestore_client.php';
                $db = get_firestore();
            }

            $incidentId  = 'incident_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(3)), 0, 6);
            $now         = new \Google\Cloud\Core\Timestamp(new \DateTime());

            $db->collection('connectivity_incidents')->document($incidentId)->set([
                'incident_id'      => $incidentId,
                'created_at'       => $now,
                'overall_status'   => $report['summary']['overall_status'],
                'verdict'          => $report['summary']['verdict'],
                'trigger_provider' => $triggerProvider,
                'trigger_context'  => $triggerContext,
                'trigger_error'    => substr($triggerError, 0, 500),
                'total_timeouts'   => $report['summary']['total_timeouts'],
                'total_failures'   => $report['summary']['total_failures'],
                'semaphore_dns_ms' => $report['raw']['semaphore_dns_ms'] ?? null,
                'semaphore_tcp_ms' => $report['raw']['semaphore_tcp_ms'] ?? null,
                'semaphore_http_ms'=> $report['raw']['semaphore_http_avg_ms'] ?? null,
                'unisms_dns_ms'    => $report['raw']['unisms_dns_ms'] ?? null,
                'unisms_tcp_ms'    => $report['raw']['unisms_tcp_ms'] ?? null,
                'unisms_http_ms'   => $report['raw']['unisms_http_avg_ms'] ?? null,
                'duration_ms'      => $report['duration_ms'],
                // Full detailed report stored as JSON string to keep Firestore doc lean
                'full_report_json' => substr(json_encode($report), 0, 60000),
            ]);

            error_log("[ConnectivityMonitor] Incident saved: {$incidentId} — {$report['summary']['overall_status']}");
        } catch (\Throwable $e) {
            // Never let monitoring failure cascade into the main request
            error_log('[ConnectivityMonitor] Failed to save incident to Firestore: ' . $e->getMessage());
        }

        return $report;
    }

    /**
     * Run all diagnostic checks and return a structured report.
     * This is the core logic — same as nola_diagnostics.php but runs in-process.
     */
    public static function runDiagnostics(): array
    {
        $report = [
            'generated_at'    => date('c'),
            'server_hostname' => gethostname() ?: 'unknown',
            'php_version'     => PHP_VERSION,
            'checks'          => [],
            'raw'             => [],
            'summary'         => [],
        ];

        $totalFailed  = 0;
        $totalTimeout = 0;

        // ── 1. DNS Checks ──────────────────────────────────────────────────────
        foreach ([
            'semaphore' => 'api.semaphore.co',
            'unisms'    => 'unismsapi.com',
        ] as $provider => $host) {
            $t0       = microtime(true);
            $ip       = @gethostbyname($host);
            $ms       = round((microtime(true) - $t0) * 1000, 2);
            $resolved = ($ip !== $host);

            $report['checks'][] = [
                'type'        => 'dns',
                'provider'    => $provider,
                'host'        => $host,
                'status'      => $resolved ? 'ok' : 'failed',
                'resolved_ip' => $resolved ? $ip : null,
                'duration_ms' => $ms,
            ];
            $report['raw'][$provider . '_dns_ms'] = $ms;

            if (!$resolved) {
                $totalFailed++;
            }
        }

        // ── 2. TCP Checks ──────────────────────────────────────────────────────
        foreach ([
            'semaphore' => 'api.semaphore.co',
            'unisms'    => 'unismsapi.com',
        ] as $provider => $host) {
            $t0   = microtime(true);
            $sock = @fsockopen("ssl://{$host}", 443, $errno, $errstr, self::CONNECT_TIMEOUT);
            $ms   = round((microtime(true) - $t0) * 1000, 2);
            $ok   = ($sock !== false);
            if ($sock) fclose($sock);

            $report['checks'][] = [
                'type'        => 'tcp',
                'provider'    => $provider,
                'host'        => $host,
                'port'        => 443,
                'status'      => $ok ? 'ok' : ($ms >= self::CONNECT_TIMEOUT * 1000 ? 'timeout' : 'failed'),
                'duration_ms' => $ms,
                'error'       => $ok ? null : "({$errno}) {$errstr}",
            ];
            $report['raw'][$provider . '_tcp_ms'] = $ms;

            if (!$ok) {
                $totalFailed++;
                if ($ms >= self::CONNECT_TIMEOUT * 1000) {
                    $totalTimeout++;
                }
            }
        }

        // ── 3. HTTP Checks ─────────────────────────────────────────────────────
        $httpTargets = [
            [
                'provider' => 'semaphore',
                'label'    => 'Semaphore Messages',
                'url'      => 'https://api.semaphore.co/api/v4/messages',
                'method'   => 'GET',
            ],
            [
                'provider' => 'unisms',
                'label'    => 'UniSMS Account',
                'url'      => 'https://unismsapi.com/api/account',
                'method'   => 'GET',
            ],
        ];

        foreach ($httpTargets as $target) {
            $runs     = [];
            $okCount  = 0;
            $totalMs  = 0;

            for ($i = 1; $i <= self::HTTP_PROBE_RUNS; $i++) {
                $ch = curl_init($target['url']);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
                curl_setopt($ch, CURLOPT_TIMEOUT, self::HTTP_TIMEOUT);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_MAXREDIRS, 2);

                $t0       = microtime(true);
                $resp     = curl_exec($ch);
                $elapsed  = round((microtime(true) - $t0) * 1000, 2);
                $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err      = curl_error($ch);
                $connMs   = round(curl_getinfo($ch, CURLINFO_CONNECT_TIME) * 1000, 2);
                curl_close($ch);

                $isOk      = ($resp !== false && $code > 0);
                $isTimeout = ($resp === false && (
                    str_contains($err, 'timed out') ||
                    str_contains($err, 'Connection timed') ||
                    str_contains($err, 'Operation timed')
                ));

                if ($isOk) $okCount++;
                if ($isTimeout) $totalTimeout++;
                if (!$isOk) $totalFailed++;
                $totalMs += $elapsed;

                $runs[] = [
                    'run'        => $i,
                    'http_code'  => $code,
                    'total_ms'   => $elapsed,
                    'connect_ms' => $connMs,
                    'ok'         => $isOk,
                    'is_timeout' => $isTimeout,
                    'curl_error' => $err ?: null,
                ];

                if ($i < self::HTTP_PROBE_RUNS) usleep(200000); // 200ms between runs
            }

            $avgMs = round($totalMs / self::HTTP_PROBE_RUNS, 2);

            $report['checks'][] = [
                'type'     => 'http',
                'provider' => $target['provider'],
                'label'    => $target['label'],
                'url'      => $target['url'],
                'status'   => $okCount === self::HTTP_PROBE_RUNS
                    ? 'ok'
                    : ($okCount > 0 ? 'intermittent' : 'failed'),
                'runs_ok'  => $okCount,
                'runs'     => $runs,
                'avg_ms'   => $avgMs,
            ];
            $report['raw'][$target['provider'] . '_http_avg_ms'] = $avgMs;
        }

        // ── Summary ────────────────────────────────────────────────────────────
        $allOk = ($totalFailed === 0 && $totalTimeout === 0);

        $report['summary'] = [
            'overall_status' => $allOk
                ? 'healthy'
                : ($totalTimeout > 0 ? 'timeout' : 'degraded'),
            'total_failures' => $totalFailed,
            'total_timeouts' => $totalTimeout,
            'verdict'        => $allOk
                ? 'All connectivity checks passed.'
                : ($totalTimeout > 0
                    ? 'TIMEOUT detected — server is having trouble reaching SMS provider APIs.'
                    : 'CONNECTIVITY FAILURES detected — some requests could not reach provider APIs.'),
        ];

        return $report;
    }
}
