<?php

namespace Tests\Unit;

use Exception;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/install_helpers.php';

final class OwnerLockSnapshot
{
    public function __construct(private bool $exists, private array $data = [], private string $id = 'doc') {}
    public function exists(): bool { return $this->exists; }
    public function data(): array { return $this->data; }
    public function id(): string { return $this->id; }
}

final class OwnerLockQuery
{
    public function __construct(private array $documents) {}
    public function limit(int $limit): self { return $this; }
    public function documents(): array { return $this->documents; }
}

final class OwnerLockReference
{
    public array $writes = [];

    public function __construct(private OwnerLockSnapshot $snapshot) {}
    public function create(array $payload): void { throw new Exception('already exists'); }
    public function snapshot(): OwnerLockSnapshot { return $this->snapshot; }
    public function set(array $payload, array $options = []): void { $this->writes[] = $payload; }
}

final class OwnerLockCollection
{
    public function __construct(
        private array $references,
        private array $emailDocuments = []
    ) {}

    public function document(string $id): OwnerLockReference
    {
        return $this->references[$id] ?? new OwnerLockReference(new OwnerLockSnapshot(false));
    }

    public function where(string $field, string $operator, string $value): OwnerLockQuery
    {
        return new OwnerLockQuery($this->emailDocuments[$value] ?? []);
    }
}

final class OwnerLockDatabase
{
    public function __construct(private array $collections) {}
    public function collection(string $name): OwnerLockCollection { return $this->collections[$name]; }
}

class InstallOwnerLockTest extends TestCase
{
    private function database(array $ownerData, ?array $activeOwner = null): array
    {
        $ownerRef = new OwnerLockReference(new OwnerLockSnapshot(true, $ownerData, 'loc-1'));
        $userRefs = [];
        $emailDocs = [];
        if ($activeOwner !== null) {
            $id = $activeOwner['id'];
            $data = $activeOwner['data'];
            $userRefs[$id] = new OwnerLockReference(new OwnerLockSnapshot(true, $data, $id));
            $emailDocs[strtolower((string)$data['email'])] = [new OwnerLockSnapshot(true, $data, $id)];
        }

        $db = new OwnerLockDatabase([
            'location_owners' => new OwnerLockCollection(['loc-1' => $ownerRef]),
            'company_owners' => new OwnerLockCollection(['loc-1' => $ownerRef]),
            'users' => new OwnerLockCollection($userRefs, $emailDocs),
        ]);

        return [$db, $ownerRef];
    }

    public function test_old_orphaned_location_owner_is_replaced(): void
    {
        [$db, $ownerRef] = $this->database([
            'owner_user_id' => 'deleted-user',
            'owner_email' => 'deleted@example.com',
            'updated_at' => '2025-01-01T00:00:00+00:00',
        ]);

        $claimed = install_claim_owner_lock(
            $db,
            'location_owners',
            'loc-1',
            'new-user',
            'new@example.com',
            'New Owner'
        );

        $this->assertTrue($claimed);
        $this->assertSame('new-user', $ownerRef->writes[0]['owner_user_id']);
        $this->assertSame('deleted-user', $ownerRef->writes[0]['previous_owner_user_id']);
        $this->assertSame('stale_location_owner_auto_replaced', $ownerRef->writes[0]['repair_source']);
    }

    public function test_active_location_owner_cannot_be_replaced(): void
    {
        [$db, $ownerRef] = $this->database([
            'owner_user_id' => 'active-user',
            'owner_email' => 'active@example.com',
            'updated_at' => '2025-01-01T00:00:00+00:00',
        ], [
            'id' => 'active-user',
            'data' => ['email' => 'active@example.com', 'active' => true, 'role' => 'user'],
        ]);

        $claimed = install_claim_owner_lock(
            $db,
            'location_owners',
            'loc-1',
            'attacker',
            'attacker@example.com',
            'Other Owner'
        );

        $this->assertFalse($claimed);
        $this->assertSame([], $ownerRef->writes);
    }

    public function test_recent_orphan_lock_is_protected_as_in_flight_registration(): void
    {
        [$db, $ownerRef] = $this->database([
            'owner_user_id' => 'pending-user',
            'owner_email' => 'pending@example.com',
            'updated_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ]);

        $claimed = install_claim_owner_lock(
            $db,
            'location_owners',
            'loc-1',
            'second-user',
            'second@example.com',
            'Second Owner'
        );

        $this->assertFalse($claimed);
        $this->assertSame([], $ownerRef->writes);
    }

    public function test_company_owner_never_uses_location_self_healing(): void
    {
        [$db, $ownerRef] = $this->database([
            'owner_user_id' => 'deleted-agency-user',
            'owner_email' => 'agency@example.com',
            'updated_at' => '2025-01-01T00:00:00+00:00',
        ]);

        $claimed = install_claim_owner_lock(
            $db,
            'company_owners',
            'loc-1',
            'new-agency-user',
            'new-agency@example.com',
            'New Agency Owner'
        );

        $this->assertFalse($claimed);
        $this->assertSame([], $ownerRef->writes);
    }
}
