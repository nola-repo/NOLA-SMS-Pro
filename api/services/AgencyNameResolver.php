<?php

final class AgencyNameResolver
{
    /**
     * Return the first non-empty scalar field instead of letting an empty
     * compatibility alias suppress a populated fallback.
     */
    public static function firstNonEmpty(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data) || !is_scalar($data[$key])) {
                continue;
            }
            $value = trim((string)$data[$key]);
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    public static function companyId(array $data, string $documentId = ''): string
    {
        return self::firstNonEmpty($data, ['company_id', 'companyId']) ?: trim($documentId);
    }

    public static function agencyName(array $data): string
    {
        return self::firstNonEmpty($data, [
            'company_name',
            'agency_name',
            'name',
        ]);
    }

    public static function agencyUserCompanyName(array $data): string
    {
        return self::firstNonEmpty($data, ['company_name', 'agency_name']);
    }

    public static function forUser(array $user, array $agencyNameMap): string
    {
        $direct = self::firstNonEmpty($user, ['agency_name', 'company_name']);
        if ($direct !== '') {
            return $direct;
        }

        $companyId = self::companyId($user);
        return $companyId !== '' ? trim((string)($agencyNameMap[$companyId] ?? '')) : '';
    }
}
