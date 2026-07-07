<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CleanupExecutionContractTest extends TestCase
{
    public function test_executor_is_dry_run_by_default_and_requires_explicit_confirmation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/cleanup_execute.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("\$execute = ce_flag('execute')", $source);
        $this->assertStringContainsString('CleanupSafety::EXECUTION_CONFIRMATION', $source);
        $this->assertStringContainsString("ce_arg('backup-confirmed'", $source);
        $this->assertStringContainsString("ce_arg('app-id'", $source);
        $this->assertStringContainsString('DRY RUN ONLY', $source);
    }

    public function test_executor_uninstalls_before_deleting_local_documents(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/cleanup_execute.php');
        $uninstall = strpos($source, '$uninstallService->uninstall(');
        $delete = strpos($source, '$batch->delete(');

        $this->assertNotFalse($uninstall);
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $uninstall);
    }

    public function test_executor_does_not_call_highlevel_conversation_or_location_delete(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/cleanup_execute.php');
        $this->assertStringNotContainsString("request('DELETE', '/conversations/", $source);
        $this->assertStringNotContainsString("request('DELETE', '/locations/", $source);
        $this->assertStringContainsString("'native_ghl_conversations' => 'preserved'", $source);
    }
}
