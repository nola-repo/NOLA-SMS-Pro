<?php

class ActivityErrorClassifier
{
    public static function fromStatus($status, ?string $provider = null, ?string $error = null, $providerResponse = null): array
    {
        $statusText = strtolower(trim((string)($status ?? '')));
        $errorText = self::stringify($error);
        if ($errorText === '' && is_array($providerResponse)) {
            $errorText = self::stringify($providerResponse['error'] ?? $providerResponse['message'] ?? $providerResponse['description'] ?? null);
        }

        if (in_array($statusText, ['sent', 'success', 'successful', 'delivered', 'completed', 'paid', 'approved', 'ok'], true)) {
            return self::payload('successful', null, 'info', false, false, null);
        }

        if (in_array($statusText, ['queued', 'pending', 'sending', 'processing', 'requested', 'provider_pending'], true)) {
            return self::payload('pending', null, 'info', false, false, null);
        }

        if ($errorText !== '') {
            return self::fromProviderFailure((string)($provider ?? ''), null, $errorText, is_array($providerResponse) ? $providerResponse : []);
        }

        if (in_array($statusText, ['failed', 'rejected', 'revoked', 'error', 'denied', 'expired', 'undelivered'], true)) {
            return self::payload('failed', 'other', 'warning', false, false, 'Provider send failed.');
        }

        return self::payload('other', null, 'info', false, false, null);
    }

    public static function fromProviderFailure(string $provider, ?int $httpCode, ?string $error, array $providerResponse = []): array
    {
        $provider = self::baseProvider($provider);
        $errorText = self::stringify($error);
        if ($errorText === '' && $providerResponse !== []) {
            $errorText = self::stringify($providerResponse['error'] ?? $providerResponse['message'] ?? $providerResponse['description'] ?? null);
        }
        $lower = strtolower($errorText);

        if (str_contains($lower, 'timed out') || str_contains($lower, 'timeout')) {
            $category = $provider === 'unisms' ? 'unisms_timeout' : ($provider === 'semaphore' ? 'semaphore_timeout' : 'provider_timeout');
            return self::payload('provider_error', $category, 'warning', false, true, self::providerLabel($provider) . ' timeout. Message may need retry or provider failover.');
        }

        if (str_contains($lower, 'unisms_likely_spam') || str_contains($lower, 'content_rejected')) {
            return self::payload('validation', 'content_rejected', 'warning', false, false, 'Provider rejected the SMS content.');
        }

        if (str_contains($lower, 'content_too_short')) {
            return self::payload('validation', 'content_too_short', 'warning', false, false, 'Message content is too short.');
        }

        if ($httpCode === 422 || str_contains($lower, 'http 422')) {
            if (str_contains($lower, 'spam') || str_contains($lower, 'rephrase your message')) {
                return self::payload('validation', 'content_rejected', 'warning', false, false, 'Provider rejected the SMS content.');
            }
            if (str_contains($lower, 'at least 10 character')) {
                return self::payload('validation', 'content_too_short', 'warning', false, false, 'Message content is too short.');
            }
            return self::payload('validation', 'provider_validation_422', 'warning', false, false, 'Provider rejected the SMS request as invalid.');
        }

        if (str_contains($lower, 'invalid_phone') || str_contains($lower, 'invalid phone') || str_contains($lower, 'invalid recipient')) {
            return self::fromValidationFailure('invalid_phone');
        }

        if (str_contains($lower, 'ghl') && str_contains($lower, 'sync')) {
            return self::payload('failed', 'ghl_sync_error', 'warning', false, true, 'GHL sync failed after SMS processing.');
        }

        if (str_contains($lower, 'exception') || str_contains($lower, 'fatal') || str_contains($lower, 'uncaught')) {
            return self::payload('failed', 'platform_exception', 'error', true, false, 'Backend platform error. Engineering review required.');
        }

        return self::payload('provider_error', 'other', 'warning', false, false, 'SMS provider rejected the request.');
    }

    public static function fromValidationFailure(string $reason): array
    {
        $reason = strtolower(trim($reason));
        if ($reason === 'invalid_phone') {
            return self::payload('validation', 'invalid_phone', 'warning', false, false, 'Invalid recipient phone number.');
        }
        if (in_array($reason, ['unisms_likely_spam', 'content_rejected'], true)) {
            return self::payload('validation', 'content_rejected', 'warning', false, false, 'Provider rejected the SMS content.');
        }
        if ($reason === 'content_too_short') {
            return self::payload('validation', 'content_too_short', 'warning', false, false, 'Message content is too short.');
        }

        return self::payload('validation', $reason !== '' ? $reason : 'other', 'warning', false, false, 'SMS request failed validation.');
    }

    private static function payload(string $statusGroup, ?string $category, string $severity, bool $isPlatformError, bool $isRetryable, ?string $summary): array
    {
        return array_filter([
            'status_group' => $statusGroup,
            'error_category' => $category,
            'severity' => $severity,
            'is_platform_error' => $isPlatformError,
            'is_retryable' => $isRetryable,
            'failure_summary' => $summary,
        ], static fn($value) => $value !== null);
    }

    private static function stringify($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_scalar($value) || (is_object($value) && method_exists($value, '__toString'))) {
            return trim((string)$value);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES);
        return trim((string)($encoded ?: ''));
    }

    private static function baseProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));
        if (in_array($provider, ['unisms', 'unisms_custom'], true)) {
            return 'unisms';
        }
        if (in_array($provider, ['semaphore', 'semaphore_custom'], true)) {
            return 'semaphore';
        }
        return $provider;
    }

    private static function providerLabel(string $provider): string
    {
        if ($provider === 'unisms') {
            return 'UniSMS';
        }
        if ($provider === 'semaphore') {
            return 'Semaphore';
        }
        return $provider !== '' ? ucfirst($provider) : 'SMS provider';
    }
}
