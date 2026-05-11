<?php

namespace App\Support;

use DateTimeInterface;
use Google\Cloud\Core\Timestamp;

final class LegacyAuthProfile
{
    public static function payloadForApi(array $doc, string $emailFallback = ''): array
    {
        $email = isset($doc['email']) ? (string) $doc['email'] : $emailFallback;
        $resolvedName = self::resolveDisplayName($doc, $email);
        $firstLast = self::firstLastFromDoc($doc, $resolvedName);
        $createdAt = self::timestampToIso8601($doc['created_at'] ?? null);
        $updatedAt = self::timestampToIso8601($doc['updated_at'] ?? null);

        return [
            'name' => $resolvedName,
            'full_name' => $resolvedName,
            'firstName' => $firstLast['firstName'],
            'lastName' => $firstLast['lastName'],
            'email' => $email,
            'email_address' => $email,
            'phone' => isset($doc['phone']) ? (string) $doc['phone'] : '',
            'phone_number' => isset($doc['phone']) ? (string) $doc['phone'] : '',
            'location_id' => $doc['active_location_id'] ?? null,
            'company_id' => $doc['company_id'] ?? null,
            'location_name' => $doc['location_name'] ?? null,
            'company_name' => $doc['company_name'] ?? null,
            'role' => $doc['role'] ?? 'user',
            'active' => isset($doc['active']) ? (bool) $doc['active'] : true,
            'source' => $doc['source'] ?? null,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ];
    }

    private static function resolveDisplayName(array $doc, string $fallbackEmail = ''): string
    {
        $name = $doc['name'] ?? null;
        if ($name !== null && trim((string) $name) !== '') {
            return trim((string) $name);
        }

        $joined = trim(($doc['firstName'] ?? '') . ' ' . ($doc['lastName'] ?? ''));
        if ($joined !== '') {
            return $joined;
        }

        return $fallbackEmail !== '' ? $fallbackEmail : '';
    }

    private static function firstLastFromDoc(array $doc, string $resolvedName): array
    {
        $firstName = isset($doc['firstName']) ? trim((string) $doc['firstName']) : '';
        $lastName = isset($doc['lastName']) ? trim((string) $doc['lastName']) : '';
        if ($firstName !== '' || $lastName !== '') {
            return ['firstName' => $firstName, 'lastName' => $lastName];
        }

        return self::splitFullName($resolvedName);
    }

    private static function splitFullName(string $full): array
    {
        $full = trim($full);
        if ($full === '') {
            return ['firstName' => '', 'lastName' => ''];
        }
        if (!preg_match('/\s/u', $full)) {
            return ['firstName' => $full, 'lastName' => ''];
        }

        $parts = preg_split('/\s+/u', $full, 2);

        return [
            'firstName' => $parts[0] ?? '',
            'lastName' => $parts[1] ?? '',
        ];
    }

    private static function timestampToIso8601(mixed $value): ?string
    {
        if ($value instanceof Timestamp) {
            return $value->get()->format('c');
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('c');
        }
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed !== '' ? $trimmed : null;
        }

        return null;
    }
}
