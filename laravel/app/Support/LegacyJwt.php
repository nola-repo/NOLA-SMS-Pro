<?php

namespace App\Support;

final class LegacyJwt
{
    public static function sign(array $payload, string $secret, int $expiresInSeconds = 28800): string
    {
        $header = self::base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + $expiresInSeconds;
        $body = self::base64UrlEncode(json_encode($payload));
        $sig = self::base64UrlEncode(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return "{$header}.{$body}.{$sig}";
    }

    public static function verify(string $token, string $secret): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$headerB64, $bodyB64, $sigB64] = $parts;
        $expected = self::base64UrlEncode(hash_hmac('sha256', "{$headerB64}.{$bodyB64}", $secret, true));
        if (!hash_equals($expected, $sigB64)) {
            return null;
        }

        $payload = json_decode(self::base64UrlDecode($bodyB64), true);
        if (!is_array($payload)) {
            return null;
        }

        if (isset($payload['exp']) && (int) $payload['exp'] < time()) {
            return null;
        }

        return $payload;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
