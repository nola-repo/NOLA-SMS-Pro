<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/SenderResolver.php';

final class SenderResolverStatusKeyTest extends TestCase
{
    public function test_saved_system_key_source_is_used_for_status_checks(): void
    {
        $resolved = \SenderResolver::resolveStatusApiKey(
            null,
            'loc_123',
            'semaphore',
            'system-key',
            false,
            'global-key',
            ['api_key_source' => 'config.SEMAPHORE_API_KEY']
        );

        $this->assertSame('system-key', $resolved['api_key']);
        $this->assertSame('config.SEMAPHORE_API_KEY', $resolved['source']);
    }

    public function test_saved_global_key_source_is_used_for_status_checks(): void
    {
        $resolved = \SenderResolver::resolveStatusApiKey(
            null,
            'loc_123',
            'semaphore',
            'system-key',
            false,
            'global-key',
            ['api_key_source' => 'config.SEMAPHORE_GLOBAL_API_KEY']
        );

        $this->assertSame('global-key', $resolved['api_key']);
        $this->assertSame('config.SEMAPHORE_GLOBAL_API_KEY', $resolved['source']);
    }

    public function test_system_sender_source_uses_system_key_even_without_system_flag(): void
    {
        $resolved = \SenderResolver::resolveStatusApiKey(
            null,
            'loc_123',
            'semaphore',
            'system-key',
            false,
            'global-key',
            ['sender_source' => 'explicit_system_sender']
        );

        $this->assertSame('system-key', $resolved['api_key']);
        $this->assertSame('config.SEMAPHORE_API_KEY', $resolved['source']);
    }
}
