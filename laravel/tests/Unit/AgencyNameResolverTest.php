<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/AgencyNameResolver.php';

final class AgencyNameResolverTest extends TestCase
{
    public function test_empty_company_name_does_not_suppress_agency_name(): void
    {
        $this->assertSame('NOLA CRM', \AgencyNameResolver::agencyName([
            'company_name' => '',
            'agency_name' => 'NOLA CRM',
        ]));
    }

    public function test_agency_user_person_name_is_not_used_as_company_name(): void
    {
        $this->assertSame('', \AgencyNameResolver::agencyUserCompanyName([
            'name' => 'Agency Owner Person',
            'company_name' => '',
        ]));
    }

    public function test_user_name_falls_back_to_company_registry_map(): void
    {
        $this->assertSame('NOLA CRM', \AgencyNameResolver::forUser([
            'company_id' => 'company-1',
            'company_name' => '',
        ], ['company-1' => 'NOLA CRM']));
    }

    public function test_unknown_company_returns_empty_for_explicit_ui_fallback(): void
    {
        $this->assertSame('', \AgencyNameResolver::forUser([
            'company_id' => 'unknown-company',
        ], []));
    }
}
