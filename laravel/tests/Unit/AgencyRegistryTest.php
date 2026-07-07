<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/install_helpers.php';

final class AgencyRegistryReference
{
    public array $writes = [];
    public function set(array $payload, array $options = []): void { $this->writes[] = $payload; }
}

final class AgencyRegistryCollection
{
    public AgencyRegistryReference $reference;
    public function __construct() { $this->reference = new AgencyRegistryReference(); }
    public function document(string $id): AgencyRegistryReference { return $this->reference; }
}

final class AgencyRegistryDatabase
{
    public AgencyRegistryCollection $agencies;
    public function __construct() { $this->agencies = new AgencyRegistryCollection(); }
    public function collection(string $name): AgencyRegistryCollection { return $this->agencies; }
}

final class AgencyRegistryTest extends TestCase
{
    public function test_empty_name_never_creates_registry_write(): void
    {
        $db = new AgencyRegistryDatabase();
        $this->assertFalse(install_upsert_agency_registry($db, 'company-1', '', 'test'));
        $this->assertSame([], $db->agencies->reference->writes);
    }

    public function test_verified_name_is_written_to_canonical_registry(): void
    {
        $db = new AgencyRegistryDatabase();
        $this->assertTrue(install_upsert_agency_registry($db, 'company-1', ' NOLA CRM ', 'test'));
        $this->assertSame('company-1', $db->agencies->reference->writes[0]['company_id']);
        $this->assertSame('NOLA CRM', $db->agencies->reference->writes[0]['company_name']);
    }
}
