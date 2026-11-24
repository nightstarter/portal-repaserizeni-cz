<?php

namespace App\Operations;

use App\Backup\BackupManifestVerifier;
use App\Backup\BackupVerification;
use App\Backup\RecoveryEvidenceStore;
use Carbon\CarbonImmutable;
use Throwable;

/** Shared read-only policy for the latest backup and the independent restore-test anchor. */
final class BackupEvidenceHealth
{
    public function __construct(
        private readonly BackupManifestVerifier $verifier,
        private readonly RecoveryEvidenceStore $evidenceStore,
    ) {}

    /** @return array<string,mixed> */
    public function latest(): array
    {
        $path = config('backups.latest_manifest');
        if (! is_string($path) || $path === '') {
            return ['status' => 'ERROR', 'configured' => false, 'off_server_confirmed' => false];
        }
        $verification = $this->verifier->verify($path);
        if (! $this->productionComplete($verification)) {
            return ['status' => 'ERROR', 'configured' => true, 'manifest_valid' => false, 'off_server_confirmed' => false];
        }
        $manifest = $verification->manifest;
        $created = CarbonImmutable::parse($manifest['created_at_utc']);
        $age = max(0, (int) $created->diffInHours(now()));
        $limit = max(1, (int) config('backups.maximum_age_hours', 26));
        $evidence = $this->evidenceStore->inspect($verification);
        $confirmed = $evidence['status'] === 'VALID'
            && data_get($evidence, 'off_server_confirmation.operator_attested_full_copy_verified') === true;

        return [
            'status' => $age > $limit ? 'ERROR' : ($confirmed ? 'OK' : 'WARN'),
            'configured' => true,
            'manifest_valid' => true,
            'backup_id' => $manifest['backup_id'],
            'created_at_utc' => $created->toIso8601String(),
            'age_hours' => $age,
            'limit_hours' => $limit,
            'off_server_confirmed' => $confirmed,
            'evidence_integrity_valid' => $evidence['status'] === 'VALID',
        ];
    }

    /** @return array<string,mixed> */
    public function restoreTest(): array
    {
        $path = config('backups.restore_evidence_manifest') ?: config('backups.latest_manifest');
        if (! is_string($path) || $path === '') {
            return ['status' => 'WARN', 'configured' => false, 'restore_test_success' => false];
        }
        $verification = $this->verifier->verify($path);
        if (! $this->productionComplete($verification)) {
            return ['status' => 'ERROR', 'configured' => true, 'manifest_valid' => false, 'restore_test_success' => false];
        }
        $evidence = $this->evidenceStore->inspect($verification);
        if ($evidence['status'] === 'MISSING') {
            return ['status' => 'WARN', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => false, 'restore_test_success' => false];
        }
        if ($evidence['status'] !== 'VALID') {
            return ['status' => 'ERROR', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => false, 'restore_test_success' => false];
        }
        $events = collect($evidence['restore_tests'] ?? [])->where('status', 'SUCCESS')->sortByDesc('completed_at_utc');
        $latest = $events->first();
        if (! is_array($latest) || ($latest['backup_id'] ?? null) !== $verification->manifest['backup_id']) {
            return ['status' => 'WARN', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => true, 'restore_test_success' => false];
        }
        try {
            if (! hash_equals($this->manifestSha256($verification), (string) ($latest['manifest_sha256'] ?? ''))) {
                return ['status' => 'ERROR', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => false, 'restore_test_success' => false];
            }
            $age = max(0, (int) CarbonImmutable::parse($latest['completed_at_utc'])->diffInDays(now()));
        } catch (Throwable) {
            return ['status' => 'ERROR', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => false, 'restore_test_success' => false];
        }
        $limit = max(1, (int) config('backups.restore_test_maximum_age_days', 100));

        return ['status' => $age <= $limit ? 'OK' : 'WARN', 'configured' => true, 'manifest_valid' => true, 'evidence_integrity_valid' => true, 'restore_test_success' => true, 'restore_test_age_days' => $age, 'restore_test_limit_days' => $limit];
    }

    private function productionComplete(BackupVerification $verification): bool
    {
        return $verification->valid && is_array($verification->manifest)
            && ($verification->manifest['environment'] ?? null) === 'production'
            && ($verification->manifest['status'] ?? null) === 'COMPLETE';
    }

    private function manifestSha256(BackupVerification $verification): string
    {
        return hash_file('sha256', $verification->root.DIRECTORY_SEPARATOR.'manifest.json') ?: '';
    }
}
