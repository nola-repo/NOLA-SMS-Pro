<?php

require __DIR__ . '/../api/install_helpers.php';

class InstallHelperTestSnapshot
{
    private bool $exists;
    private array $data;
    private string $id;
    private $reference;

    public function __construct(bool $exists, array $data = [], string $id = 'doc', $reference = null)
    {
        $this->exists = $exists;
        $this->data = $data;
        $this->id = $id;
        $this->reference = $reference;
    }

    public function exists(): bool { return $this->exists; }
    public function data(): array { return $this->data; }
    public function id(): string { return $this->id; }
    public function reference() { return $this->reference; }
}

class InstallHelperTestReference
{
    private $parent;
    private $snapshot;

    public function __construct($parent = null, $snapshot = null)
    {
        $this->parent = $parent;
        $this->snapshot = $snapshot;
    }

    public function parent() { return $this->parent; }
    public function snapshot() { return $this->snapshot; }
}

class InstallHelperTestCollection
{
    private $parent;

    public function __construct($parent)
    {
        $this->parent = $parent;
    }

    public function parent() { return $this->parent; }
}

$locationId = 'LOC123456789';

$parentSnap = new InstallHelperTestSnapshot(true, [
    'email' => 'Owner@Example.com',
    'name' => 'Owner Name',
    'role' => 'user',
], 'user1');
$parentRef = new InstallHelperTestReference(null, $parentSnap);
$subRef = new InstallHelperTestReference(new InstallHelperTestCollection($parentRef), null);
$subSnap = new InstallHelperTestSnapshot(true, [], $locationId, $subRef);
$linked = install_linked_account_from_subaccount_doc($subSnap, $locationId, 'test');

if (($linked['email'] ?? '') !== 'owner@example.com') {
    fwrite(STDERR, "subaccount fallback failed\n");
    exit(1);
}

$userSnap = new InstallHelperTestSnapshot(true, [
    'email' => 'a@b.com',
    'location_id' => $locationId,
    'role' => 'user',
], 'user2');
$linked2 = install_linked_account_from_user_doc($userSnap, $locationId, 'test2');

if (($linked2['id'] ?? '') !== 'user2') {
    fwrite(STDERR, "user alias fallback failed\n");
    exit(1);
}

$single = install_resolve_selected_location([
    'locations' => [['id' => $locationId, 'name' => 'Selected']],
]);
if (empty($single['ok']) || ($single['location_id'] ?? '') !== $locationId || ($single['source'] ?? '') !== 'locations_unique_single') {
    fwrite(STDERR, "single location resolution failed\n");
    exit(1);
}

$otherLocationId = 'LOC987654321';
$stateMatched = install_resolve_selected_location([
    'approved_location_ids' => [$locationId, $otherLocationId],
    'state_location_id' => $otherLocationId,
]);
if (empty($stateMatched['ok']) || ($stateMatched['location_id'] ?? '') !== $otherLocationId || ($stateMatched['source'] ?? '') !== 'oauth_state') {
    fwrite(STDERR, "state matched candidate resolution failed\n");
    exit(1);
}

$conflict = install_resolve_selected_location([
    'approved_location_ids' => [$locationId],
    'token_location_id' => $otherLocationId,
]);
if (empty($conflict['ok']) || ($conflict['location_id'] ?? '') !== $locationId || ($conflict['source'] ?? '') !== 'approved_locations_unique_single') {
    fwrite(STDERR, "picker-over-token conflict resolution failed\n");
    exit(1);
}

$dupApproved = install_resolve_selected_location([
    'approved_location_ids' => [$locationId, $locationId],
    'locations' => [['id' => $locationId, 'name' => 'A'], ['id' => $locationId, 'name' => 'B']],
]);
if (empty($dupApproved['ok']) || ($dupApproved['location_id'] ?? '') !== $locationId) {
    fwrite(STDERR, "deduped approved/locations resolution failed\n");
    exit(1);
}

$ambiguous = install_resolve_selected_location([
    'approved_location_ids' => [$locationId, $otherLocationId],
]);
if (!empty($ambiguous['ok']) || ($ambiguous['reason'] ?? '') !== 'ambiguous_location_candidates') {
    fwrite(STDERR, "ambiguous candidate resolution failed\n");
    exit(1);
}

$sessionSelected = install_resolve_selected_location([
    'approved_location_ids' => [$locationId, $otherLocationId],
    'session_location_id' => $locationId,
]);
if (empty($sessionSelected['ok']) || ($sessionSelected['location_id'] ?? '') !== $locationId || ($sessionSelected['source'] ?? '') !== 'signed_install_session') {
    fwrite(STDERR, "signed session resolution failed\n");
    exit(1);
}

$marketplaceTier = install_resolve_selected_location([
    'token_marketplace_selected_id' => $locationId,
    'approved_location_ids' => [$locationId, $otherLocationId],
]);
if (empty($marketplaceTier['ok']) || ($marketplaceTier['location_id'] ?? '') !== $locationId || strpos((string)($marketplaceTier['source'] ?? ''), 'ghl_token_marketplace_selected') === false) {
    fwrite(STDERR, "marketplace token tier resolution failed\n");
    exit(1);
}

echo "helper fallback and resolution checks passed\n";
