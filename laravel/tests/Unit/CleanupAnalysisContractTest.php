<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class CleanupAnalysisContractTest extends TestCase
{
    public function test_analyzer_remains_read_only_and_emits_tamper_evident_manifest(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/cleanup_analysis.php');
        $this->assertIsString($source);

        $this->assertStringContainsString("'manifest_version' => CleanupSafety::MANIFEST_VERSION", $source);
        $this->assertStringContainsString("['manifest_sha256'] = CleanupSafety::manifestDigest", $source);
        $this->assertStringContainsString("'app_id_source'", $source);
        $this->assertStringNotContainsString('->delete(', $source);
        $this->assertStringNotContainsString('->set(', $source);
        $this->assertStringNotContainsString('->batch(', $source);
    }
}
