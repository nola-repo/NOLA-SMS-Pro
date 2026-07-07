<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AgencyNameMigrationContractTest extends TestCase
{
    public function test_migration_is_dry_run_by_default_and_requires_confirmation(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/migrate_agency_name_registry.php');
        $this->assertIsString($source);
        $this->assertStringContainsString("\$execute = manr_flag('execute')", $source);
        $this->assertStringContainsString('MIGRATE-AGENCY-NAME-REGISTRY', $source);
        $this->assertStringContainsString("'mode' => \$execute ? 'execute' : 'dry_run'", $source);
    }

    public function test_migration_only_deletes_empty_company_name_fields(): void
    {
        $source = file_get_contents(__DIR__ . '/../../../scripts/migrate_agency_name_registry.php');
        $this->assertStringContainsString("trim((string)\$tokenData['company_name']) === ''", $source);
        $this->assertStringContainsString("trim((string)\$intData['company_name']) === ''", $source);
        $this->assertStringContainsString('FieldValue::deleteField()', $source);
    }
}
