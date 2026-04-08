<?php
/**
 * jwt_helper.php
 * Lightweight HS256 JWT sign/verify — no composer library needed.
 * Used by: register.php, login.php, ghl_autologin.php, link_company.php
 */

function jwt_sign(array $payload, string $secret, int $expiresInSeconds = 28800): string
{
    $header  = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresInSeconds;
    $body    = base64url_encode(json_encode($payload));
    $sig     = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function jwt_verify(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$headerB64, $bodyB64, $sigB64] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$headerB64.$bodyB64", $secret, true));
    if (!hash_equals($expected, $sigB64)) return null;

    $payload = json_decode(base64url_decode($bodyB64), true);
    if (!is_array($payload)) return null;
    if (isset($payload['exp']) && $payload['exp'] < time()) return null;

    return $payload;
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
