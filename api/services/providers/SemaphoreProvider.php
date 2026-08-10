<?php

require_once __DIR__ . '/SmsProviderInterface.php';
require_once __DIR__ . '/../ConnectivityMonitor.php';

class SemaphoreProvider implements SmsProviderInterface
{
    private $defaultApiKey;
    private $apiUrl;

    // Retry configuration (shared by all methods)
    private const MAX_ATTEMPTS  = 3;
    private const BASE_DELAY_MS = 500;  // 500 ms after attempt 1
    private const MAX_DELAY_MS  = 3000; // cap at 3 s

    /**
     * Fire-and-forget diagnostics — runs in the same process but after the
     * exception is thrown, so it never blocks or delays the error response.
     * Saves an incident report to Firestore → connectivity_incidents.
     */
    private static function triggerDiagnostics(string $provider, string $error, string $context): void
    {
        try {
            ConnectivityMonitor::runAndSave($provider, $error, $context);
        } catch (\Throwable $e) {
            error_log('[SemaphoreProvider] ConnectivityMonitor failed: ' . $e->getMessage());
        }
    }

    public function __construct(array $config = [])
    {
        $this->defaultApiKey = $config['SEMAPHORE_API_KEY'] ?? '';
        $this->apiUrl        = $config['SEMAPHORE_URL'] ?? 'https://api.semaphore.co/api/v4/messages';
    }

    private function getApiKey(?string $apiKey): string
    {
        return !empty($apiKey) ? $apiKey : $this->defaultApiKey;
    }

    /**
     * Compute retry delay with exponential backoff + jitter.
     * attempt 1 → ~500 ms, attempt 2 → ~1 000 ms, capped at MAX_DELAY_MS.
     */
    private function retryDelayMs(int $attempt): int
    {
        $base   = self::BASE_DELAY_MS * (2 ** ($attempt - 1));
        $jitter = random_int(0, (int)($base * 0.3));
        return min($base + $jitter, self::MAX_DELAY_MS);
    }

    /**
     * Execute a raw cURL call with automatic retry on transient failures.
     *
     * Empirical Latency Rationale (from connectivity_incidents & logs):
     * - p50: ~80 ms | p95: ~655 ms | p99: ~4,200 ms
     * - Default connectTimeout = 6s (p99 4.2s + 1.8s buffer)
     * - Default totalTimeout   = 8s
     * - Total retry cap        = ~6.5s overhead
     *
     * @param  string $url
     * @param  string $method   'GET' or 'POST'
     * @param  mixed  $payload  JSON body for POST (null for GET)
     * @param  array  $headers  HTTP headers
     * @param  int    $connectTimeout  cURL connect timeout in seconds
     * @param  int    $totalTimeout    cURL total timeout in seconds
     * @param  int    $maxAttempts    cURL max retry attempts
     * @return array{response:string|false, httpCode:int, curlError:string}
     */
    private function curlWithRetry(
        string $url,
        string $method,
        $payload,
        array  $headers = [],
        int    $connectTimeout = 6,
        int    $totalTimeout   = 8,
        int    $maxAttempts    = 3,
        bool   $sleepOnRateLimit = true
    ): array {
        $lastResponse  = false;
        $lastHttpCode  = 0;
        $lastCurlError = '';
        $lastErrNo     = 0;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $startTime = microtime(true);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $totalTimeout);
            curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
            curl_setopt($ch, CURLOPT_TCP_NODELAY, 1); // Disable Nagle's algorithm for instant packet dispatch
            curl_setopt($ch, CURLOPT_ENCODING, '');
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 3);

            // Determine content type and body encoding
            $isJson = false;
            foreach ($headers as $h) {
                if (stripos($h, 'application/json') !== false) {
                    $isJson = true;
                    break;
                }
            }

            if (empty($headers) && $method === 'POST') {
                $headers = ["Content-Type: application/x-www-form-urlencoded"];
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            // Capture response headers so we can read Retry-After on 429
            $responseHeaders = [];
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($header);
            });

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if (is_array($payload)) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $isJson ? json_encode($payload) : http_build_query($payload));
                } else {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            }

            $lastResponse  = curl_exec($ch);
            $lastHttpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastCurlError = curl_error($ch);
            $lastErrNo     = curl_errno($ch);
            curl_close($ch);

            $latencyMs = round((microtime(true) - $startTime) * 1000, 1);

            // Success
            if ($lastResponse !== false && $lastHttpCode >= 200 && $lastHttpCode < 300) {
                if ($attempt > 1) {
                    error_log(json_encode([
                        'event'          => 'sms_retry_attempt',
                        'provider'       => 'semaphore',
                        'attempt_number' => $attempt,
                        'max_attempts'   => $maxAttempts,
                        'latency_ms'     => $latencyMs,
                        'http_code'      => $lastHttpCode,
                        'outcome'        => 'success_on_retry'
                    ]));
                }
                break;
            }

            $isConnectionError = ($lastResponse === false);
            $isServerError     = ($lastHttpCode >= 500 && $lastHttpCode < 600);
            $isRateLimit       = ($lastHttpCode === 429);
            $isTransient       = $isConnectionError || $isServerError || $isRateLimit;

            // Fail-Fast check: Hard socket errors (DNS fail, connection refused <1s) should stop early
            $isHardSocketError = $isConnectionError && $latencyMs < 1000 && in_array($lastErrNo, [6, 7, 35], true);
            if ($isHardSocketError && $attempt >= 2) {
                error_log(json_encode([
                    'event'          => 'sms_retry_attempt',
                    'provider'       => 'semaphore',
                    'attempt_number' => $attempt,
                    'max_attempts'   => $maxAttempts,
                    'latency_ms'     => $latencyMs,
                    'curl_errno'     => $lastErrNo,
                    'curl_error'     => $lastCurlError,
                    'outcome'        => 'fail_fast_aborted'
                ]));
                break;
            }

            if ($isTransient && $attempt < $maxAttempts) {
                $delayMs = $isRateLimit ? random_int(400, 800) : $this->retryDelayMs($attempt);

                // Structured JSON logging for every retry event
                error_log(json_encode([
                    'event'          => 'sms_retry_attempt',
                    'provider'       => 'semaphore',
                    'attempt_number' => $attempt,
                    'max_attempts'   => $maxAttempts,
                    'latency_ms'     => $latencyMs,
                    'http_code'      => $lastHttpCode,
                    'curl_errno'     => $lastErrNo,
                    'curl_error'     => $lastCurlError,
                    'delay_ms'       => $delayMs,
                    'outcome'        => 'retrying'
                ]));

                usleep($delayMs * 1000);
                continue;
            }

            // Non-transient (other 4xx) or last attempt — stop retrying
            break;
        }

        return [
            'response'  => $lastResponse,
            'httpCode'  => $lastHttpCode,
            'curlError' => $lastCurlError,
        ];
    }

    public function sendSingle(string $number, string $message, string $senderId, ?string $apiKey = null): array
    {
        return $this->sendBulk([$number], $message, $senderId, $apiKey);
    }

    public function sendBulk(array $numbers, string $message, string $senderId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $payload = [
            'apikey'     => $resolvedKey,
            'number'     => implode(',', $numbers),
            'message'    => $message,
            'sendername' => $senderId,
        ];

        // Empirically derived: connectTimeout = 6s, totalTimeout = 8s, maxAttempts = 3
        $result = $this->curlWithRetry(
            $this->apiUrl,
            'POST',
            $payload,
            ["Content-Type: application/x-www-form-urlencoded"],
            6,
            8,
            3
        );

        if ($result['response'] === false) {
            // Final failure after all retries — auto-run diagnostics in background
            self::triggerDiagnostics('semaphore', 'cURL error: ' . $result['curlError'], 'send');
            throw new \Exception("Semaphore cURL error: " . $result['curlError']);
        }

        $httpCode = $result['httpCode'];
        $decoded  = json_decode($result['response'], true);

        if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
            $msg = $decoded['message'] ?? $decoded['error'] ?? 'Semaphore HTTP ' . $httpCode;
            throw new \Exception("Semaphore send failed: " . $msg);
        }

        // Return standardized list
        $results = [];
        $isList  = array_keys($decoded) === range(0, count($decoded) - 1);
        if ($isList) {
            foreach ($decoded as $row) {
                if (is_array($row) && isset($row['message_id'])) {
                    $results[] = [
                        'message_id'            => (string)$row['message_id'],
                        'provider_reference_id' => (string)$row['message_id'],
                        'provider_message_id'   => (string)$row['message_id'],
                        'status'                => $this->normalizeStatus($row['status'] ?? 'queued'),
                        'recipient'             => $row['number'] ?? '',
                        'provider_response'     => $row,
                    ];
                }
            }
        } elseif (isset($decoded['message_id'])) {
            $results[] = [
                'message_id'            => (string)$decoded['message_id'],
                'provider_reference_id' => (string)$decoded['message_id'],
                'provider_message_id'   => (string)$decoded['message_id'],
                'status'                => $this->normalizeStatus($decoded['status'] ?? 'queued'),
                'recipient'             => $decoded['number'] ?? '',
                'provider_response'     => $decoded,
            ];
        }

        if (empty($results)) {
            throw new \Exception("Semaphore response missing message_id");
        }

        return $results;
    }

    public function checkStatus(string $messageId, ?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $url = "https://api.semaphore.co/api/v4/messages/" . urlencode($messageId) . "?apikey=" . urlencode($resolvedKey);

        $result   = $this->curlWithRetry($url, 'GET', null, [], 8, 10);
        $httpCode = $result['httpCode'];
        $response = $result['response'];

        if ($httpCode === 404) {
            return ['status' => 'not_found'];
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'error'];
        }

        $decoded   = json_decode($response, true);
        $statusStr = '';
        if (is_array($decoded)) {
            if (isset($decoded[0]['status'])) {
                $statusStr = $decoded[0]['status'];
            } elseif (isset($decoded['status'])) {
                $statusStr = $decoded['status'];
            }
        }

        if ($statusStr) {
            return ['status' => $this->normalizeStatus($statusStr)];
        }

        return ['status' => 'sending'];
    }

    public function checkAccount(?string $apiKey = null): array
    {
        $resolvedKey = $this->getApiKey($apiKey);
        $url = "https://api.semaphore.co/api/v4/account?apikey=" . urlencode($resolvedKey);

        $result   = $this->curlWithRetry($url, 'GET', null, [], 2, 4, 1, false);
        $httpCode = $result['httpCode'];
        $response = $result['response'];

        if ($httpCode === 429) {
            return ['status' => 'error', 'credits' => 0, 'error' => 'HTTP 429 Rate Limited'];
        }

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            return ['status' => 'inactive', 'credits' => 0];
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded) && isset($decoded['credit_balance'])) {
            return [
                'status'  => 'active',
                'credits' => (int)$decoded['credit_balance'],
            ];
        }

        return ['status' => 'inactive', 'credits' => 0];
    }

    public function normalizeStatus(string $rawStatus): string
    {
        $l = strtolower(trim($rawStatus));
        if (in_array($l, ['queued', 'pending'])) {
            return 'queued';
        }
        if ($l === 'sending') {
            return 'sending';
        }
        if (in_array($l, ['sent', 'success', 'delivered'])) {
            return 'sent';
        }
        if (in_array($l, ['failed', 'expired', 'rejected', 'undelivered'])) {
            return 'failed';
        }
        return $l;
    }
}
