<?php

namespace Tests\Feature;

use App\Backup\BackupManifestVerifier;
use App\Backup\RecoveryEvidenceException;
use App\Backup\RecoveryEvidenceStore;
use App\Operations\PreflightCheck;
use App\Operations\ProductionPreflight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\BackupFixture;
use Tests\TestCase;

final class Phase8bRecoveryEvidenceTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<BackupFixture> */
    private array $fixtures = [];

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'backups.testing_evidence_fail_before_rename' => false,
            'integrations.legacy_shop.enabled' => false,
            'discussion_notifications.enabled' => false,
            'buyback.customer_email_enabled' => false,
            'integrations.pohoda_bridge.enabled' => false,
            'integrations.pohoda_bridge.allowed_command_types' => [],
            'operations.product_repricing' => 'DEFERRED_EXTERNAL_SYSTEM',
        ]);
    }

    protected function tearDown(): void
    {
        config(['backups.testing_evidence_fail_before_rename' => false]);
        foreach ($this->fixtures as $fixture) {
            $fixture->cleanup();
        }
        parent::tearDown();
    }

    public function test_off_server_confirmation_is_atomic_bound_idempotent_and_does_not_mutate_backup(): void
    {
        $fixture = $this->fixture(['documents/safe.pdf' => 'customer-content-canary']);
        $before = $this->componentHashes($fixture);
        config([
            'database.connections.sqlite.password' => 'db-secret-canary',
            'mail.mailers.smtp.password' => 'smtp-secret-canary',
        ]);

        $this->assertSame(1, Artisan::call('backup:evidence:off-server', [
            'path' => $fixture->root(), '--evidence-id' => 'CHG-8B-001', '--json' => true,
        ]));
        $this->assertFileDoesNotExist($fixture->root().'/recovery-evidence.json');

        $this->assertSame(0, Artisan::call('backup:evidence:off-server', [
            'path' => $fixture->root(), '--evidence-id' => 'CHG-8B-001', '--confirmed' => true, '--json' => true,
        ]));
        $this->assertSame($before, $this->componentHashes($fixture));
        $sidecar = (string) file_get_contents($fixture->root().'/recovery-evidence.json');
        $envelope = json_decode($sidecar, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($fixture->manifest()['backup_id'], $envelope['backup_id']);
        $this->assertSame(hash_file('sha256', $fixture->root().'/manifest.json'), $envelope['manifest_sha256']);
        $this->assertSame('production', $envelope['environment']);
        $this->assertStringNotContainsString($fixture->root(), $sidecar);
        $this->assertStringNotContainsString('customer-content-canary', $sidecar);
        $this->assertStringNotContainsString('secret-canary', $sidecar);
        $this->assertStringNotContainsString('CHG-8B-001', $sidecar);

        $sidecarHash = hash_file('sha256', $fixture->root().'/recovery-evidence.json');
        $this->assertSame(0, Artisan::call('backup:evidence:off-server', [
            'path' => $fixture->root(), '--evidence-id' => 'CHG-8B-001', '--confirmed' => true, '--json' => true,
        ]));
        $this->assertTrue(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['idempotent']);
        $this->assertSame($sidecarHash, hash_file('sha256', $fixture->root().'/recovery-evidence.json'));

        $this->assertSame(1, Artisan::call('backup:evidence:off-server', [
            'path' => $fixture->root(), '--evidence-id' => 'CHG-8B-CONFLICT', '--confirmed' => true, '--json' => true,
        ]));
        $this->assertSame($before, $this->componentHashes($fixture));
    }

    public function test_invalid_signature_and_sidecar_from_another_backup_are_rejected_without_invalidating_backup(): void
    {
        $fixture = $this->fixture();
        app(RecoveryEvidenceStore::class)->confirmOffServer($fixture->root(), 'CHG-SIGNATURE');
        $path = $fixture->root().'/recovery-evidence.json';
        $outer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $outer['sealed_evidence'][10] = $outer['sealed_evidence'][10] === 'A' ? 'B' : 'A';
        file_put_contents($path, json_encode($outer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $verification = app(BackupManifestVerifier::class)->verify($fixture->root());
        $this->assertSame('INVALID', app(RecoveryEvidenceStore::class)->inspect($verification)['status']);
        $this->assertSame(0, Artisan::call('backup:verify', ['path' => $fixture->root(), '--json' => true]));
        $this->assertSame('WARN', json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['recovery_evidence']['status']);

        $source = $this->fixture();
        app(RecoveryEvidenceStore::class)->confirmOffServer($source->root(), 'CHG-OTHER-BACKUP');
        $target = $this->fixture();
        copy($source->root().'/recovery-evidence.json', $target->root().'/recovery-evidence.json');
        $targetVerification = app(BackupManifestVerifier::class)->verify($target->root());
        $this->assertSame('INVALID', app(RecoveryEvidenceStore::class)->inspect($targetVerification)['status']);
    }

    public function test_atomic_failure_cleans_temporary_file_and_preserves_existing_sidecar(): void
    {
        $fixture = $this->fixture();
        $store = app(RecoveryEvidenceStore::class);
        $store->confirmOffServer($fixture->root(), 'CHG-ATOMIC');
        $path = $fixture->root().'/recovery-evidence.json';
        $before = hash_file('sha256', $path);
        config(['backups.testing_evidence_fail_before_rename' => true]);

        try {
            $store->recordRestoreSuccess(
                app(BackupManifestVerifier::class)->verify($fixture->root()),
                hash_file('sha256', $fixture->root().'/manifest.json'),
                'RESTORE-ATOMIC',
                1.0,
                $this->metrics($fixture),
            );
            $this->fail('Evidence publication failure was expected.');
        } catch (RecoveryEvidenceException) {
            $this->assertSame($before, hash_file('sha256', $path));
        }
        $this->assertSame([], glob($fixture->root().'/.recovery-evidence-*.tmp'));
    }

    public function test_restore_evidence_replay_is_idempotent_and_conflicting_replay_is_rejected(): void
    {
        $fixture = $this->fixture();
        $verification = app(BackupManifestVerifier::class)->verify($fixture->root());
        $store = app(RecoveryEvidenceStore::class);
        $manifestHash = hash_file('sha256', $fixture->root().'/manifest.json');
        $metrics = $this->metrics($fixture);

        $first = $store->recordRestoreSuccess($verification, $manifestHash, 'RESTORE-REPLAY', 1.0, $metrics);
        $replay = $store->recordRestoreSuccess($verification, $manifestHash, 'RESTORE-REPLAY', 2.0, $metrics);
        $this->assertFalse($first['idempotent']);
        $this->assertTrue($replay['idempotent']);
        $this->assertCount(1, $store->inspect($verification)['restore_tests']);

        $conflictingMetrics = $metrics;
        $conflictingMetrics['private_file_count']++;
        unset($conflictingMetrics['counts_fingerprint']);
        $conflictingMetrics['counts_fingerprint'] = hash('sha256', json_encode($conflictingMetrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $this->expectException(RecoveryEvidenceException::class);
        $store->recordRestoreSuccess($verification, $manifestHash, 'RESTORE-REPLAY', 2.0, $conflictingMetrics);
    }

    public function test_preflight_distinguishes_missing_invalid_stale_and_complete_evidence(): void
    {
        $fixture = $this->fixture();
        config(['backups.latest_manifest' => $fixture->root(), 'backups.restore_test_maximum_age_days' => 100]);
        $this->assertSame(PreflightCheck::WARN, $this->recoveryPreflight()->status);

        $store = app(RecoveryEvidenceStore::class);
        $store->confirmOffServer($fixture->root(), 'CHG-PREFLIGHT');
        $this->assertSame(PreflightCheck::WARN, $this->recoveryPreflight()->status);
        $verification = app(BackupManifestVerifier::class)->verify($fixture->root());
        $store->recordRestoreSuccess(
            $verification,
            hash_file('sha256', $fixture->root().'/manifest.json'),
            'RESTORE-PREFLIGHT',
            2.0,
            $this->metrics($fixture),
        );
        $this->assertSame(PreflightCheck::OK, $this->recoveryPreflight()->status);

        $outer = json_decode((string) file_get_contents($fixture->root().'/recovery-evidence.json'), true, flags: JSON_THROW_ON_ERROR);
        $outer['backup_id'] = 'foreign-backup-id';
        file_put_contents($fixture->root().'/recovery-evidence.json', json_encode($outer));
        $this->assertSame(PreflightCheck::ERROR, $this->recoveryPreflight()->status);
    }

    public function test_preflight_warns_for_a_stale_successful_restore_test(): void
    {
        $fixture = $this->fixture();
        config(['backups.latest_manifest' => $fixture->root(), 'backups.restore_test_maximum_age_days' => 90]);
        $store = app(RecoveryEvidenceStore::class);
        $store->confirmOffServer($fixture->root(), 'CHG-STALE');
        $verification = app(BackupManifestVerifier::class)->verify($fixture->root());

        $this->travelTo(now()->subDays(91));
        $store->recordRestoreSuccess(
            $verification,
            hash_file('sha256', $fixture->root().'/manifest.json'),
            'RESTORE-STALE',
            2.0,
            $this->metrics($fixture),
        );
        $this->travelBack();

        $check = $this->recoveryPreflight();
        $this->assertSame(PreflightCheck::WARN, $check->status);
        $this->assertSame(91, $check->context['restore_test_age_days']);
    }

    private function fixture(array $files = []): BackupFixture
    {
        return $this->fixtures[] = BackupFixture::create($files);
    }

    /** @return array<string,string> */
    private function componentHashes(BackupFixture $fixture): array
    {
        $hashes = [];
        foreach (['manifest.json', 'database.sql', 'private-storage.tar', 'metadata.json'] as $file) {
            $hashes[$file] = hash_file('sha256', $fixture->root().'/'.$file);
        }

        return $hashes;
    }

    /** @return array<string,mixed> */
    private function metrics(BackupFixture $fixture): array
    {
        $manifest = $fixture->manifest();
        $metrics = [
            'critical_table_counts' => $manifest['database']['critical_counts'],
            'schema_fingerprint' => $manifest['schema']['fingerprint'],
            'private_file_count' => count(collect($manifest['parts'])->firstWhere('kind', 'private-archive')['entries']),
        ];
        $metrics['counts_fingerprint'] = hash('sha256', json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $metrics;
    }

    private function recoveryPreflight(): object
    {
        return collect(app(ProductionPreflight::class)->run())->firstWhere('id', 'recovery_evidence');
    }
}
