<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/jwt_helper.php';
require_once __DIR__ . '/../../../api/install_helpers.php';

class JwtHelperTest extends TestCase
{
    public function test_detailed_verification_accepts_valid_token(): void
    {
        $result = jwt_verify_detailed(jwt_sign(['sub' => 'user-1'], 'secret', 60), 'secret');

        $this->assertTrue($result['valid']);
        $this->assertSame('ok', $result['reason']);
        $this->assertSame('user-1', $result['payload']['sub']);
    }

    public function test_detailed_verification_distinguishes_expired_token(): void
    {
        $result = jwt_verify_detailed(jwt_sign(['sub' => 'user-1'], 'secret', -1), 'secret');

        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['reason']);
        $this->assertNull($result['payload']);
    }

    public function test_detailed_verification_rejects_invalid_signature(): void
    {
        $token = jwt_sign(['sub' => 'user-1'], 'secret', 60);
        $result = jwt_verify_detailed($token, 'different-secret');

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_signature', $result['reason']);
        $this->assertNull($result['payload']);
    }

    public function test_registration_link_has_enough_time_to_complete_onboarding(): void
    {
        putenv('INSTALL_REGISTRATION_TOKEN_TTL_SECONDS');
        $url = install_build_registration_url(
            'secret',
            'Q0f8zUVCIhH5w74AX35K',
            'Faith AI Demo',
            'IoJXjFNuM0Pyh0JaU0ME',
            'Agency',
            'unit_test'
        );
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $result = jwt_verify_detailed((string)($query['install_token'] ?? ''), 'secret');

        $this->assertTrue($result['valid']);
        $this->assertSame(3600, $result['payload']['exp'] - $result['payload']['iat']);
        $this->assertSame('Q0f8zUVCIhH5w74AX35K', $result['payload']['location_id']);
    }
}
