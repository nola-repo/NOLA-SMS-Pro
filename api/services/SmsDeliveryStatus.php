<?php

namespace Nola\Services;

/**
 * Maps provider delivery states to NOLA UI statuses.
 * UniSMS "sent" means accepted by the carrier, not confirmed on the handset.
 */
class SmsDeliveryStatus
{
    public static function isUnismsProvider(?string $provider): bool
    {
        $provider = strtolower(trim((string)$provider));
        return $provider === 'unisms' || str_starts_with($provider, 'unisms_');
    }

    public static function rawProviderStatusToLocal(?string $provider, ?string $rawStatus, ?string $event = null): string
    {
        $raw = strtolower(trim((string)($rawStatus ?: $event)));
        if ($raw === '') {
            return 'Sending';
        }

        if (str_contains($raw, 'fail') || str_contains($raw, 'reject') || str_contains($raw, 'undelivered') || str_contains($raw, 'expired')) {
            return 'Failed';
        }

        if (str_contains($raw, 'delivered')) {
            return 'Sent';
        }

        if (self::isUnismsProvider($provider)) {
            if (in_array($raw, ['sent', 'success'], true)) {
                return 'Sending';
            }
        } elseif (in_array($raw, ['sent', 'success', 'delivered'], true)) {
            return 'Sent';
        }

        if (in_array($raw, ['queued', 'pending', 'retrying', 'sending'], true)) {
            return 'Sending';
        }

        return 'Sending';
    }

    public static function mapStoredStatus(?string $storedStatus, ?string $providerStatus = null, ?string $provider = null): ?string
    {
        if (!$storedStatus) {
            return null;
        }

        $stored = strtolower(trim($storedStatus));
        $providerRaw = strtolower(trim((string)$providerStatus));

        if (self::isUnismsProvider($provider) && $stored === 'sent' && $providerRaw === 'sent') {
            return 'Sending';
        }

        if (in_array($stored, ['queued', 'pending', 'sending'], true)) {
            return 'Sending';
        }
        if (in_array($stored, ['sent', 'success', 'delivered'], true)) {
            return 'Sent';
        }
        if (in_array($stored, ['failed', 'expired'], true)) {
            return 'Failed';
        }

        return ucfirst($stored);
    }

    public static function initialStatusFromGateway(?string $provider, ?string $rawStatus): string
    {
        return self::rawProviderStatusToLocal($provider, $rawStatus);
    }
}
