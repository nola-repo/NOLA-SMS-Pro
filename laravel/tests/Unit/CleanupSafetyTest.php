<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../api/services/CleanupSafety.php';
require_once __DIR__ . '/../../../api/install_helpers.php';

class CleanupSafetyTest extends TestCase
{
    public function test_every_production_location_is_hard_protected(): void
    {
        foreach (\CleanupSafety::PROTECTED_LOCATIONS as $locationId => $name) {
            $this->assertTrue(\CleanupSafety::isProtectedLocation($locationId), $name);
        }
        $this->assertFalse(\CleanupSafety::isProtectedLocation('DisposableTestLocation123'));
    }

    public function test_manifest_digest_detects_content_changes(): void
    {
        $manifest = $this->manifestFixture();
        $manifest['manifest_sha256'] = \CleanupSafety::manifestDigest($manifest);

        $this->assertSame([], \CleanupSafety::validateManifest(
            $manifest,
            $manifest['manifest_sha256'],
            3600
        ));

        $manifest['candidate_count'] = 2;
        $errors = \CleanupSafety::validateManifest($manifest, $manifest['manifest_sha256'], 3600);
        $this->assertContains('Manifest content digest is invalid.', $errors);
    }

    public function test_shared_candidate_document_requires_every_candidate_to_be_approved(): void
    {
        $manifest = $this->manifestFixture();
        $manifest['unique_document_decisions'] = [
            [
                'path' => 'users/shared',
                'collection' => 'users',
                'final_action' => 'would_delete',
                'candidate_ids' => ['locationA123', 'locationB123'],
            ],
            [
                'path' => 'messages/retained',
                'collection' => 'messages',
                'final_action' => 'retain_shared_dependency',
                'candidate_ids' => ['locationA123'],
            ],
            [
                'path' => 'credit_transactions/immutable',
                'collection' => 'credit_transactions',
                'final_action' => 'would_delete',
                'candidate_ids' => ['locationA123'],
            ],
        ];

        $this->assertSame([], \CleanupSafety::approvedDeletionDecisions($manifest, ['locationA123']));
        $approved = \CleanupSafety::approvedDeletionDecisions($manifest, ['locationA123', 'locationB123']);
        $this->assertCount(1, $approved);
        $this->assertSame('users/shared', $approved[0]['path']);
    }

    public function test_nonzero_balance_and_pending_work_block_candidate(): void
    {
        $candidate = [
            'location_id' => 'DisposableTestLocation123',
            'counts' => [
                'credits' => ['manual_review_nonzero_balance' => 1],
                'messages' => ['manual_review_pending' => 2],
                'scheduled_jobs' => ['retain_shared_dependency' => 20],
            ],
        ];

        $this->assertSame(
            ['nonzero_balance', 'pending_work'],
            \CleanupSafety::candidateBlockers($candidate)
        );
    }

    public function test_cleanup_lock_disables_sms(): void
    {
        $this->assertFalse(\install_token_active_for_sms(true, [
            'install_state' => 'INSTALLED',
            'is_live' => true,
            'cleanup_in_progress' => true,
        ]));
    }

    private function manifestFixture(): array
    {
        return [
            'manifest_version' => \CleanupSafety::MANIFEST_VERSION,
            'dry_run' => true,
            'firestore_mutations_performed' => 0,
            'generated_at' => gmdate(DATE_ATOM),
            'production_allowlist' => \CleanupSafety::PROTECTED_LOCATIONS,
            'scan' => ['complete' => true],
            'candidate_count' => 1,
            'candidates' => [],
            'unique_document_decisions' => [],
        ];
    }
}
