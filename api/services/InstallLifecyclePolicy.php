<?php

final class InstallLifecyclePolicy
{
    public const DEFAULT_PENDING_TTL_SECONDS = 86400;

    public static function timestampUnix($value): ?int
    {
        try {
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                return $value->get()->getTimestamp();
            }
            if ($value instanceof DateTimeInterface) {
                return $value->getTimestamp();
            }
            if (is_int($value) || (is_string($value) && ctype_digit($value))) {
                return (int)$value;
            }
            if (is_string($value) && trim($value) !== '') {
                return (new DateTimeImmutable($value))->getTimestamp();
            }
        } catch (Throwable $ignored) {
        }

        return null;
    }

    public static function pendingStartedAt(array $tokenData): ?int
    {
        foreach (['oauth_pending_started_at', 'updated_at', 'created_at'] as $field) {
            $timestamp = self::timestampUnix($tokenData[$field] ?? null);
            if ($timestamp !== null) {
                return $timestamp;
            }
        }

        return null;
    }

    public static function pendingIsStale(array $tokenData, int $nowUnix, int $ttlSeconds): bool
    {
        if ((string)($tokenData['install_state'] ?? '') !== 'PENDING_OAUTH') {
            return false;
        }
        if (($tokenData['cleanup_in_progress'] ?? false) === true) {
            return false;
        }

        $startedAt = self::pendingStartedAt($tokenData);
        if ($startedAt === null) {
            return false;
        }

        return ($nowUnix - $startedAt) >= max(3600, $ttlSeconds);
    }
}
