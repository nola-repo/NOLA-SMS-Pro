<?php

/**
 * Lightweight, fail-open performance instrumentation for legacy PHP endpoints.
 *
 * The logger never records request/response bodies, authorization headers, user
 * identifiers, or other PII. It emits one JSON line for slow or failed requests
 * and must never interfere with the API response if instrumentation itself fails.
 */
final class NolaPerformance
{
    private static ?array $request = null;
    private static array $activeSpans = [];

    public static function start(string $route, array $dimensions = []): void
    {
        if (self::$request !== null || !self::enabled()) {
            return;
        }

        try {
            $requestId = self::requestId();
            $safeDimensions = [];
            foreach ($dimensions as $key => $value) {
                $safeKey = self::safeName((string)$key);
                if ($safeKey !== '' && (is_string($value) || is_int($value) || is_bool($value))) {
                    $safeDimensions[$safeKey] = is_string($value)
                        ? substr(preg_replace('/[^a-zA-Z0-9_.:\/-]/', '_', $value) ?? '', 0, 100)
                        : $value;
                }
            }

            self::$request = [
                'started_ns' => hrtime(true),
                'request_id' => $requestId,
                'route' => substr($route, 0, 160),
                'method' => strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI')),
                'dimensions' => $safeDimensions,
                'timings_ms' => [],
                'counters' => [],
                'cache_status' => null,
            ];

            if (!headers_sent()) {
                header('X-Request-ID: ' . $requestId);
                header('Server-Timing: total;dur=0');
            }

            register_shutdown_function([self::class, 'shutdown']);
        } catch (Throwable $e) {
            self::$request = null;
        }
    }

    public static function begin(string $name): void
    {
        if (self::$request === null) {
            return;
        }

        $name = self::safeName($name);
        if ($name !== '') {
            self::$activeSpans[$name] = hrtime(true);
        }
    }

    public static function end(string $name): void
    {
        if (self::$request === null) {
            return;
        }

        $name = self::safeName($name);
        if ($name === '' || !isset(self::$activeSpans[$name])) {
            return;
        }

        $elapsedMs = (hrtime(true) - self::$activeSpans[$name]) / 1_000_000;
        unset(self::$activeSpans[$name]);
        self::$request['timings_ms'][$name] = round(
            (float)(self::$request['timings_ms'][$name] ?? 0) + $elapsedMs,
            2
        );
        self::updateServerTimingHeader();
    }

    public static function increment(string $name, int $amount = 1): void
    {
        if (self::$request === null) {
            return;
        }

        $name = self::safeName($name);
        if ($name !== '') {
            self::$request['counters'][$name] = (int)(self::$request['counters'][$name] ?? 0) + $amount;
        }
    }

    public static function cache(string $status): void
    {
        if (self::$request === null) {
            return;
        }

        $status = strtoupper(trim($status));
        self::$request['cache_status'] = in_array($status, ['HIT', 'MISS', 'BYPASS', 'STALE'], true)
            ? $status
            : 'UNKNOWN';
    }

    public static function shutdown(): void
    {
        if (self::$request === null) {
            return;
        }

        try {
            foreach (array_keys(self::$activeSpans) as $name) {
                self::end($name);
            }

            $totalMs = round((hrtime(true) - self::$request['started_ns']) / 1_000_000, 2);
            $status = http_response_code();
            $status = is_int($status) && $status >= 100 ? $status : 200;

            $serverTiming = ['total;dur=' . $totalMs];
            foreach (self::$request['timings_ms'] as $name => $duration) {
                $serverTiming[] = $name . ';dur=' . $duration;
            }
            if (!headers_sent()) {
                header('Server-Timing: ' . implode(', ', $serverTiming));
            }

            if ($totalMs < self::slowThresholdMs() && $status < 500) {
                return;
            }

            $payload = array_filter([
                'type' => 'api_performance',
                'severity' => $status >= 500 ? 'ERROR' : 'INFO',
                'route' => self::$request['route'],
                'method' => self::$request['method'],
                'request_id' => self::$request['request_id'],
                'status' => $status,
                'total_ms' => $totalMs,
                'cache_status' => self::$request['cache_status'],
                'timings_ms' => self::$request['timings_ms'],
                'counters' => self::$request['counters'],
                'dimensions' => self::$request['dimensions'],
            ], static fn($value) => $value !== null && $value !== []);

            $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            if (is_string($json)) {
                $written = @file_put_contents('php://stderr', $json . PHP_EOL);
                if ($written === false) {
                    error_log('[NOLA_PERF] ' . $json);
                }
            }
        } catch (Throwable $e) {
            // Performance instrumentation is deliberately fail-open.
        } finally {
            self::$request = null;
            self::$activeSpans = [];
        }
    }

    private static function enabled(): bool
    {
        $value = strtolower(trim((string)(getenv('NOLA_PERF_ENABLED') ?: 'true')));
        return !in_array($value, ['0', 'false', 'off', 'no'], true);
    }

    private static function slowThresholdMs(): float
    {
        $configured = getenv('NOLA_PERF_SLOW_MS');
        if ($configured === false || !is_numeric($configured)) {
            return 500.0;
        }
        return max(0.0, (float)$configured);
    }

    private static function requestId(): string
    {
        if (class_exists('Logger') && method_exists('Logger', 'requestId')) {
            $loggerRequestId = Logger::requestId();
            if (is_string($loggerRequestId) && $loggerRequestId !== '') {
                return $loggerRequestId;
            }
        }

        $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
        if ($incoming !== '' && strlen($incoming) <= 128 && preg_match('/^[a-zA-Z0-9._:-]+$/', $incoming)) {
            return $incoming;
        }

        try {
            return bin2hex(random_bytes(12));
        } catch (Throwable $e) {
            return str_replace('.', '', uniqid('req_', true));
        }
    }

    private static function safeName(string $name): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($name)) ?? '', 0, 64);
    }

    private static function updateServerTimingHeader(): void
    {
        if (self::$request === null || headers_sent()) {
            return;
        }

        $elapsedMs = round((hrtime(true) - self::$request['started_ns']) / 1_000_000, 2);
        $serverTiming = ['total;dur=' . $elapsedMs];
        foreach (self::$request['timings_ms'] as $name => $duration) {
            $serverTiming[] = $name . ';dur=' . $duration;
        }
        header('Server-Timing: ' . implode(', ', $serverTiming));
    }
}
