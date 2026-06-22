<?php

class ApiValueFormatter
{
    public static function timestamp($value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            if ($value instanceof \Google\Cloud\Core\Timestamp) {
                return $value->get()->format(DATE_ATOM);
            }

            if ($value instanceof \DateTimeInterface) {
                return $value->format(DATE_ATOM);
            }

            if (is_object($value) && method_exists($value, 'get')) {
                $resolved = $value->get();
                if ($resolved instanceof \DateTimeInterface) {
                    return $resolved->format(DATE_ATOM);
                }
            }

            if (is_object($value) && method_exists($value, 'formatAsString')) {
                $formatted = trim((string)$value->formatAsString());
                return $formatted !== '' ? $formatted : null;
            }

            if (is_string($value)) {
                $formatted = trim($value);
                return $formatted !== '' ? $formatted : null;
            }

            if (is_int($value) || is_float($value)) {
                $date = (new \DateTimeImmutable('@' . (string)(int)$value))
                    ->setTimezone(new \DateTimeZone('UTC'));
                return $date->format(DATE_ATOM);
            }
        } catch (\Throwable $ignored) {
        }

        return null;
    }
}
