<?php

class ReferenceId
{
    public static function generate(string $prefix): string
    {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]+/i', '', $prefix));
        if ($prefix === '') {
            $prefix = 'REF';
        }

        return sprintf('%s-%s-%s', $prefix, gmdate('Ymd'), strtoupper(bin2hex(random_bytes(4))));
    }

    public static function keepOrGenerate($value, string $prefix): string
    {
        $existing = trim((string)($value ?? ''));
        return $existing !== '' ? $existing : self::generate($prefix);
    }
}