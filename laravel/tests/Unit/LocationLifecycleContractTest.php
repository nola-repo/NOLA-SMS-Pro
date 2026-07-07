<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__ . '/../../../api/services/LocationBootstrapService.php';

class LocationLifecycleContractTest extends TestCase
{
    public static function blockedStates(): array
    {
        return [
            'not installed' => [
                ['status' => 'FRESH_INSTALL'],
                [],
                'LOCATION_NOT_INSTALLED',
                'show_not_installed',
            ],
            'oauth pending' => [
                ['status' => 'INSTALL_PENDING', 'install_state' => 'PENDING_OAUTH'],
                ['install_state' => 'PENDING_OAUTH'],
                'LOCATION_INSTALL_PENDING',
                'show_retry',
            ],
            'registration required' => [
                ['status' => 'TOKEN_ONLY', 'install_state' => 'INSTALLED'],
                ['install_state' => 'INSTALLED', 'is_live' => true],
                'LOCATION_REGISTRATION_REQUIRED',
                'complete_registration',
            ],
            'company mismatch' => [
                ['status' => 'COMPANY_MISMATCH'],
                ['install_state' => 'INSTALLED', 'is_live' => true],
                'LOCATION_COMPANY_MISMATCH',
                'show_retry',
            ],
            'uninstalled overrides linked account' => [
                ['status' => 'LINKED_ACCOUNT', 'install_state' => 'UNINSTALLED'],
                ['install_state' => 'UNINSTALLED', 'is_live' => false],
                'LOCATION_UNINSTALLED',
                'show_not_installed',
            ],
            'cleanup blocks before product load' => [
                ['status' => 'LINKED_ACCOUNT', 'install_state' => 'INSTALLED'],
                ['install_state' => 'INSTALLED', 'is_live' => true, 'cleanup_in_progress' => true],
                'LOCATION_CLEANUP_IN_PROGRESS',
                'show_retry',
            ],
            'expired onboarding requires restart' => [
                ['status' => 'TOKEN_ONLY', 'install_state' => 'ONBOARDING_EXPIRED'],
                ['install_state' => 'ONBOARDING_EXPIRED', 'is_live' => false],
                'LOCATION_ONBOARDING_EXPIRED',
                'show_not_installed',
            ],
        ];
    }

    #[DataProvider('blockedStates')]
    public function test_blocked_state_contract(
        array $classification,
        array $tokenData,
        string $expectedCode,
        string $expectedAction
    ): void {
        $block = \LocationBootstrapService::installBlock($classification, $tokenData);

        $this->assertNotNull($block);
        $this->assertSame($expectedCode, $block['code']);
        $this->assertSame($expectedAction, $block['next_action']);
    }

    public function test_registered_location_advances_to_session_and_token_checks(): void
    {
        $block = \LocationBootstrapService::installBlock(
            ['status' => 'LINKED_ACCOUNT', 'install_state' => 'INSTALLED'],
            ['install_state' => 'INSTALLED', 'is_live' => true]
        );

        $this->assertNull($block);
    }

    public function test_location_id_validation_rejects_company_like_and_generic_values(): void
    {
        $this->assertTrue(\LocationBootstrapService::isValidLocationId('Q0f8zUVCIhH5w74AX35K'));
        $this->assertFalse(\LocationBootstrapService::isValidLocationId('102249651'));
        $this->assertFalse(\LocationBootstrapService::isValidLocationId('location'));
        $this->assertFalse(\LocationBootstrapService::isValidLocationId(''));
    }
}
