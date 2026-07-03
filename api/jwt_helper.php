<?php
/**
 * jwt_helper.php
 * Lightweight HS256 JWT sign/verify — no composer library needed.
 * Used by: register.php, login.php, ghl_autologin.php, link_company.php
 */

function jwt_sign(array $payload, string $secret, int $expiresInSeconds = 28800): string
{
    $header = base64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresInSeconds;
    $body = base64url_encode(json_encode($payload));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$body", $secret, true));
    return "$header.$body.$sig";
}

function jwt_verify(string $token, string $secret): ?array
{
    $result = jwt_verify_detailed($token, $secret);
    return $result['valid'] ? $result['payload'] : null;
}

/**
 * Verify a JWT while preserving a safe machine-readable failure reason.
 * Callers must never trust payload data unless valid=true.
 *
 * @return array{valid:bool,payload:?array,reason:string}
 */
function jwt_verify_detailed(string $token, string $secret): array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return ['valid' => false, 'payload' => null, 'reason' => 'malformed'];
    }

    [$headerB64, $bodyB64, $sigB64] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$headerB64.$bodyB64", $secret, true));
    if (!hash_equals($expected, $sigB64)) {
        return ['valid' => false, 'payload' => null, 'reason' => 'invalid_signature'];
    }

    $payload = json_decode(base64url_decode($bodyB64), true);
    if (!is_array($payload)) {
        return ['valid' => false, 'payload' => null, 'reason' => 'invalid_payload'];
    }
    if (isset($payload['exp']) && (int)$payload['exp'] < time()) {
        return ['valid' => false, 'payload' => null, 'reason' => 'expired'];
    }

    return ['valid' => true, 'payload' => $payload, 'reason' => 'ok'];
}

function base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string
{
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
