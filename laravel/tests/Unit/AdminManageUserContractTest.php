<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminManageUserContractTest extends TestCase
{
    public function test_subaccount_reset_preserves_sender_approval_configuration(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../api/admin_manage_user.php');
        $this->assertIsString($source);

        $resetStart = strpos($source, "if (\$action === 'reset')");
        $deleteStart = strpos($source, "if (\$action === 'delete')");
        $this->assertNotFalse($resetStart);
        $this->assertNotFalse($deleteStart);

        $resetBlock = substr($source, $resetStart, $deleteStart - $resetStart);

        $this->assertStringContainsString("'credit_balance'", $resetBlock);
        $this->assertStringContainsString("'free_usage_count'", $resetBlock);
        $this->assertStringNotContainsString("'approved_sender_id' => null", $resetBlock);
        $this->assertStringNotContainsString("'provider_preference' => 'system'", $resetBlock);
        $this->assertStringContainsString("'approved_sender_preserved'", $resetBlock);
    }
}
