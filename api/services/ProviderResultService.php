<?php

class ProviderResultService
{
    private const FAILED_STATUSES = ['failed', 'expired', 'rejected', 'undelivered'];

    public static function providerMessageValidation(string $providerPreference, string $message): ?array
    {
        return null;
    }

    public static function summarizeGatewayResults(array $results): array
    {
        $errors = [];
        $providerHttpStatus = null;
        $failedCount = 0;

        foreach ($results as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (!self::isFailedStatus($row['status'] ?? null)) {
                continue;
            }

            $failedCount++;
            $error = $row['error'] ?? 'Provider send failed';
            $errors[] = $error;

            $extracted = self::extractHttpStatus($row, is_scalar($error) ? (string)$error : json_encode($error));
            if ($extracted !== null) {
                $providerHttpStatus = $extracted;
            }
        }

        return [
            'errors' => $errors,
            'failed_count' => $failedCount,
            'provider_http_status' => $providerHttpStatus,
            'http_status' => empty($errors) ? 200 : self::publicFailureStatus($providerHttpStatus),
        ];
    }

    public static function summarizeGatewayException(\Throwable $e): array
    {
        $providerHttpStatus = self::extractHttpStatus([], $e->getMessage());

        return [
            'errors' => [$e->getMessage()],
            'failed_count' => 1,
            'provider_http_status' => $providerHttpStatus,
            'http_status' => self::publicFailureStatus($providerHttpStatus),
        ];
    }

    public static function accepted(array $messageResults, array $savedMessageIds, int $httpStatus, array $errors): bool
    {
        if ($httpStatus < 200 || $httpStatus >= 300 || empty($savedMessageIds) || !empty($errors)) {
            return false;
        }

        $failedResultCount = 0;
        foreach ($messageResults as $msg) {
            if (is_array($msg) && self::isFailedStatus($msg['status'] ?? null)) {
                $failedResultCount++;
            }
        }

        return $failedResultCount < count($savedMessageIds);
    }

    public static function failureMessage(array $gatewayErrors, string $provider): string
    {
        $firstError = self::firstGatewayError($gatewayErrors);
        if ($firstError !== null) {
            return $firstError;
        }

        return self::providerDisplayName($provider) . ' rejected the SMS request.';
    }

    public static function firstGatewayError(array $gatewayErrors): ?string
    {
        foreach ($gatewayErrors as $error) {
            $clean = self::compactGatewayError(is_scalar($error) ? (string)$error : json_encode($error));
            if ($clean !== null) {
                return $clean;
            }
        }

        return null;
    }

    public static function compactGatewayError(?string $error): ?string
    {
        $error = trim((string)($error ?? ''));
        if ($error === '') {
            return null;
        }

        $error = preg_replace('/\s+/', ' ', $error);
        $error = self::redactSensitiveText($error);
        return substr($error, 0, 500);
    }

    private static function redactSensitiveText(string $error): string
    {
        $error = preg_replace('/(api[_-]?key|token|secret|authorization|password)\s*[:=]\s*[^,\s"]+/i', '$1=[redacted]', $error);
        $error = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/=-]+/i', 'Bearer [redacted]', $error);

        return $error;
    }

    public static function publicFailureStatus(?int $providerHttpStatus): int
    {
        if (in_array($providerHttpStatus, [408, 504], true)) {
            return 504;
        }

        return 502;
    }

    public static function isFailedStatus($status): bool
    {
        return in_array(strtolower((string)($status ?? '')), self::FAILED_STATUSES, true);
    }

    private static function baseProvider(string $providerPreference): string
    {
        $provider = strtolower(trim($providerPreference));
        if (in_array($provider, ['unisms', 'unisms_custom'], true)) {
            return 'unisms';
        }
        if (in_array($provider, ['semaphore', 'semaphore_custom'], true)) {
            return 'semaphore';
        }

        return $provider;
    }

    private static function extractHttpStatus(array $row, string $error): ?int
    {
        if (isset($row['provider_http_status']) && is_numeric($row['provider_http_status'])) {
            return (int)$row['provider_http_status'];
        }

        if (preg_match('/HTTP\s+(\d{3})/i', $error, $m)) {
            return (int)$m[1];
        }

        return null;
    }

    private static function providerDisplayName(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if ($provider === 'unisms') {
            return 'UniSMS';
        }
        if ($provider === 'semaphore') {
            return 'Semaphore';
        }

        return $provider !== '' ? ucfirst($provider) : 'SMS provider';
    }
}
