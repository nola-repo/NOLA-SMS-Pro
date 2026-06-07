<?php

/**
 * Logger.php — Production-Ready Logging for NOLA SMS PRO
 *
 * Features:
 *  - Dual-sink: writes to both terminal (error_log → stdout/Cloud Logging) and
 *    a daily rotating log file (api/logs/app-YYYY-MM-DD.log).
 *  - request_id: a short random ID ties REQUEST → AUTH → RESPONSE for one HTTP call.
 *  - Sensitive data redaction: secrets, tokens, and Authorization headers are masked.
 *  - Fail-safe: ALL logging operations are wrapped in try/catch so a logging failure
 *    NEVER propagates to the request or breaks the application.
 *
 * Usage (automatic via cors.php):
 *   Logger::init()                         — log incoming request (called once by cors.php)
 *   Logger::auth(bool, string, array)      — log auth success/failure
 *   Logger::error(string, array)           — log errors (catch blocks, bad input)
 *   Logger::response(int, array|null)      — log outgoing response
 *   Logger::info(string, array)            — general-purpose info
 */

class Logger
{
    /** @var string|null Unique ID for this HTTP request lifecycle */
    private static ?string $requestId = null;

    /** @var string Absolute path to today's log file */
    private static string $logFile = '';

    /** @var bool Whether file logging is available */
    private static bool $fileLogEnabled = false;

    /** @var bool Whether Logger::init() has been called */
    private static bool $initialized = false;

    /**
     * Keys whose values are always redacted in log output.
     * Case-insensitive match against both header names and array keys.
     */
    private const REDACTED_KEYS = [
        'x-webhook-secret',
        'authorization',
        'x-admin-auth',
        'secret',
        'token',
        'password',
        'webhook_secret',
        'jwt_secret',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Initialize the logger for this request.
     * Safe to call multiple times — only runs once per request lifecycle.
     * Called automatically by cors.php.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        try {
            // Generate a short unique ID for this request
            self::$requestId = bin2hex(random_bytes(4)); // 8 hex chars

            // Set up the daily log file path
            $logDir = __DIR__ . '/logs';
            $today  = date('Y-m-d');
            self::$logFile = $logDir . '/app-' . $today . '.log';

            // Check if we can write to the log directory
            if (is_dir($logDir) && is_writable($logDir)) {
                self::$fileLogEnabled = true;
            }

            // Log the incoming request
            self::_write('INFO', 'REQUEST', [
                'method'  => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
                'uri'     => self::_safeUri(),
                'ip'      => self::_clientIp(),
                'ua'      => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120),
            ]);
        } catch (\Throwable $ignored) {
            // Logging must never crash the app
        }
    }

    /**
     * Log an authentication event.
     *
     * @param bool   $success   true = authenticated, false = rejected
     * @param string $method    e.g. 'webhook-secret', 'jwt', 'optional-jwt'
     * @param array  $context   Additional safe context (no secrets)
     */
    public static function auth(bool $success, string $method, array $context = []): void
    {
        try {
            $level = $success ? 'INFO' : 'WARNING';
            $outcome = $success ? 'SUCCESS' : 'FAILED';
            self::_write($level, 'AUTH', array_merge([
                'outcome' => $outcome,
                'method'  => $method,
                'ip'      => self::_clientIp(),
            ], $context));
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Log an error event.
     *
     * @param string $message  Human-readable description
     * @param array  $context  Additional safe context (avoid raw user input)
     */
    public static function error(string $message, array $context = []): void
    {
        try {
            // Capture the call site for easier debugging
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
            $caller = $trace[1] ?? $trace[0] ?? [];
            self::_write('ERROR', 'ERROR', array_merge([
                'message' => $message,
                'file'    => isset($caller['file']) ? basename($caller['file']) : null,
                'line'    => $caller['line'] ?? null,
            ], $context));
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Log the outgoing HTTP response.
     *
     * @param int        $statusCode  HTTP status code being sent
     * @param array|null $body        Subset of the response body (safe fields only)
     */
    public static function response(int $statusCode, ?array $body = null): void
    {
        try {
            $level = $statusCode >= 500 ? 'ERROR' : ($statusCode >= 400 ? 'WARNING' : 'INFO');
            $context = ['status' => $statusCode];
            if ($body !== null) {
                // Only log the 'success' / 'error' fields — never full payloads
                foreach (['success', 'error', 'message', 'status'] as $key) {
                    if (array_key_exists($key, $body)) {
                        $context[$key] = $body[$key];
                    }
                }
            }
            self::_write($level, 'RESPONSE', $context);
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Log a general-purpose informational message.
     *
     * @param string $message  Description
     * @param array  $context  Additional safe context
     */
    public static function info(string $message, array $context = []): void
    {
        try {
            self::_write('INFO', 'INFO', array_merge(['message' => $message], $context));
        } catch (\Throwable $ignored) {
        }
    }

    /**
     * Expose the current request_id so endpoints can attach it to their
     * own error responses if desired.
     */
    public static function requestId(): ?string
    {
        return self::$requestId;
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Core write — emits one JSON line to stdout AND the daily log file.
     */
    private static function _write(string $level, string $event, array $extra = []): void
    {
        try {
            $entry = array_filter([
                'ts'         => date('c'),              // ISO 8601 with timezone
                'level'      => $level,
                'event'      => $event,
                'request_id' => self::$requestId,
            ], fn($v) => $v !== null);

            // Merge extra fields, redacting any sensitive keys
            foreach (self::_redact($extra) as $k => $v) {
                $entry[$k] = $v;
            }

            $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

            // Sink 1: Terminal / stdout (picked up by Docker / Cloud Logging)
            error_log('[NOLA] ' . rtrim($line));

            // Sink 2: Daily rotating log file
            if (self::$fileLogEnabled && self::$logFile !== '') {
                file_put_contents(self::$logFile, $line, FILE_APPEND | LOCK_EX);
            }
        } catch (\Throwable $ignored) {
            // Absolute last resort — never crash the app
        }
    }

    /**
     * Recursively redact sensitive keys from an array.
     */
    private static function _redact(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            $keyLower = strtolower((string)$key);
            $isSensitive = false;
            foreach (self::REDACTED_KEYS as $redactedKey) {
                if (str_contains($keyLower, $redactedKey)) {
                    $isSensitive = true;
                    break;
                }
            }
            if ($isSensitive) {
                $out[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $out[$key] = self::_redact($value);
            } else {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Build a safe URI string — strips secret/token query params.
     */
    private static function _safeUri(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // Remove sensitive query parameters from the logged URI
        $uri = preg_replace('/([?&])(secret|token|webhook_secret)=[^&]*/i', '$1$2=[REDACTED]', $uri);
        return (string)$uri;
    }

    /**
     * Best-effort client IP (respects X-Forwarded-For from Cloud Run/proxies).
     */
    private static function _clientIp(): string
    {
        $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($forwarded) {
            // X-Forwarded-For can be a comma-separated list; take the first
            return trim(explode(',', $forwarded)[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
