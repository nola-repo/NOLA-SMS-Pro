<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/InstallLifecyclePolicy.php';

class InstallLifecyclePolicyTest extends TestCase
{
    public function test_only_old_pending_oauth_records_are_stale(): void
    {
        $now = 2_000_000;

        $this->assertTrue(\InstallLifecyclePolicy::pendingIsStale([
            'install_state' => 'PENDING_OAUTH',
            'oauth_pending_started_at' => $now - 86_401,
        ], $now, 86_400));

        $this->assertFalse(\InstallLifecyclePolicy::pendingIsStale([
            'install_state' => 'PENDING_OAUTH',
            'oauth_pending_started_at' => $now - 60,
        ], $now, 86_400));

        $this->assertFalse(\InstallLifecyclePolicy::pendingIsStale([
            'install_state' => 'INSTALLED',
            'oauth_pending_started_at' => $now - 200_000,
        ], $now, 86_400));
    }

    public function test_cleanup_locked_pending_record_is_not_expired(): void
    {
        $this->assertFalse(\InstallLifecyclePolicy::pendingIsStale([
            'install_state' => 'PENDING_OAUTH',
            'oauth_pending_started_at' => 1,
            'cleanup_in_progress' => true,
        ], 2_000_000, 86_400));
    }
}
