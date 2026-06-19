<?php

class FirestoreId
{
    public static function sanitize(string $value, int $maxLength = 400): string
    {
        $clean = preg_replace('/[^a-zA-Z0-9_.:-]/', '_', $value);
        return substr($clean ?: hash('sha256', $value), 0, $maxLength);
    }

    public static function smsLogId(
        string $locationId,
        string $provider,
        string $providerMessageId,
        string $recipient,
        ?string $secondaryId = null
    ): string {
        $parts = array_filter([
            'sms',
            $locationId,
            $provider ?: 'provider',
            $recipient ?: 'recipient',
            $secondaryId ?: null,
            $providerMessageId,
        ], static fn($value) => trim((string)$value) !== '');

        return self::sanitize(implode('_', $parts));
    }
}
