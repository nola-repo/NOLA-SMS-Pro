<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/api/install_helpers.php';
require_once dirname(__DIR__, 3) . '/api/jwt_helper.php';

class CrmDomainDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        unset($_SERVER['HTTP_REFERER']);
    }

    public function test_detects_nolacrm_from_referer(): void
    {
        $domain = install_detect_crm_base_url('https://app.nolacrm.io/v2/location/123');
        $this->assertEquals('https://app.nolacrm.io', $domain);
    }

    public function test_detects_gohighlevel_from_referer(): void
    {
        $domain = install_detect_crm_base_url('https://app.gohighlevel.com/v2/location/456');
        $this->assertEquals('https://app.gohighlevel.com', $domain);
    }

    public function test_detects_leadconnectorhq_from_referer(): void
    {
        $domain = install_detect_crm_base_url('https://marketplace.leadconnectorhq.com/apps/install');
        $this->assertEquals('https://app.gohighlevel.com', $domain);
    }

    public function test_detects_custom_whitelabel_domain(): void
    {
        $domain = install_detect_crm_base_url(null, null, 'https://app.customagency.com/');
        $this->assertEquals('https://app.customagency.com', $domain);
    }

    public function test_detects_origin_from_json_state(): void
    {
        $state = json_encode(['origin' => 'https://app.gohighlevel.com']);
        $domain = install_detect_crm_base_url(null, $state);
        $this->assertEquals('https://app.gohighlevel.com', $domain);
    }

    public function test_falls_back_to_default_when_no_signals_present(): void
    {
        $domain = install_detect_crm_base_url('', '');
        $this->assertEquals('https://app.nolacrm.io', $domain);
    }

    public function test_embeds_detected_crm_domain_into_install_token(): void
    {
        $jwtSecret = 'test_secret_for_unit_tests_123456';
        $regUrl = install_build_registration_url(
            $jwtSecret,
            'loc_test_123',
            'Test Location',
            'comp_test_456',
            'Test Company',
            'unit_test',
            'fresh_install',
            [],
            'https://app.gohighlevel.com'
        );

        $parsed = parse_url($regUrl);
        parse_str($parsed['query'] ?? '', $query);
        $token = $query['install_token'] ?? '';
        $this->assertNotEmpty($token);

        $payload = jwt_verify($token, $jwtSecret);
        $this->assertIsArray($payload);
        $this->assertEquals('https://app.gohighlevel.com', $payload['crm_domain'] ?? null);
        $this->assertEquals('loc_test_123', $payload['location_id'] ?? null);
    }
}
