<?php

class PhoneNormalizer
{
    /**
     * Normalize Philippine mobile numbers to local 09XXXXXXXXX format.
     *
     * @param mixed $numberString String, array, or comma/semicolon-separated input.
     * @return list<string>
     */
    public static function philippineMobiles($numberString): array
    {
        if (!$numberString) {
            return [];
        }

        $numbers = is_array($numberString) ? $numberString : preg_split('/[,;]/', (string)$numberString);
        $valid = [];

        foreach ($numbers as $num) {
            $normalized = self::philippineMobile((string)$num);
            if ($normalized !== null) {
                $valid[$normalized] = true;
            }
        }

        return array_keys($valid);
    }

    public static function philippineMobile(string $number): ?string
    {
        $num = trim($number);
        $num = preg_replace('/[^0-9+]/', '', $num);
        $digits = ltrim($num, '+');

        if (preg_match('/^09\d{9}$/', $digits)) {
            return $digits;
        }
        if (preg_match('/^9\d{9}$/', $digits)) {
            return '0' . $digits;
        }
        if (preg_match('/^639\d{9}$/', $digits)) {
            return '0' . substr($digits, 2);
        }
        if (preg_match('/^63(9\d{9})$/', $digits, $m)) {
            return '0' . $m[1];
        }

        return null;
    }
}
