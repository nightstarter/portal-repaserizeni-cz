<?php

namespace App\Backup;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Throwable;

final class RecoveryEvidenceStore
{
    private const FORMAT_VERSION = 1;

    public function __construct(private readonly BackupManifestVerifier $verifier) {}

    /** @return array<string,mixed> */
    public function confirmOffServer(string $backupPath, string $evidenceId): array
    {
        $verification = $this->verifiedProductionBackup($backupPath);
        $this->assertEvidenceId($evidenceId);
        $evidence = $this->loadOrCreate($verification);
        $existing = $evidence['off_server_confirmation'];
        if (is_array($existing)) {
            if (($existing['evidence_id'] ?? null) === $evidenceId) {
                return $this->offServerResult($verification, $existing, true);
            }
            throw new RecoveryEvidenceException('A conflicting off-server confirmation already exists.');
        }

        $confirmation = [
            'evidence_id' => $evidenceId,
            'confirmed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'operator_attested_full_copy_verified' => true,
        ];
        $evidence['off_server_confirmation'] = $confirmation;
        $this->write($verification, $evidence);

        return $this->offServerResult($verification, $confirmation, false);
    }

    /** @param array<string,mixed> $safeMetrics
     * @return array<string,mixed>
     */
    public function recordRestoreSuccess(
        BackupVerification $verification,
        string $expectedManifestSha256,
        string $evidenceId,
        float $durationSeconds,
        array $safeMetrics,
    ): array {
        $this->assertVerifiedProductionBackup($verification);
        if (preg_match('/\A[0-9a-f]{64}\z/', $expectedManifestSha256) !== 1
            || ! hash_equals($expectedManifestSha256, $this->manifestSha256($verification))) {
            throw new RecoveryEvidenceException('The verified backup manifest changed before evidence recording.');
        }
        $fresh = $this->verifier->verify($verification->root);
        $this->assertVerifiedProductionBackup($fresh);
        if (($fresh->manifest['backup_id'] ?? null) !== ($verification->manifest['backup_id'] ?? null)
            || ! hash_equals($expectedManifestSha256, $this->manifestSha256($fresh))) {
            throw new RecoveryEvidenceException('The verified backup changed before evidence recording.');
        }
        $verification = $fresh;
        $this->assertEvidenceId($evidenceId);
        $this->assertSafeMetrics($safeMetrics);
        $evidence = $this->loadOrCreate($verification);
        $event = [
            'status' => 'SUCCESS',
            'evidence_id' => $evidenceId,
            'completed_at_utc' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'duration_seconds' => round(max(0, $durationSeconds), 3),
            'backup_id' => $verification->manifest['backup_id'],
            'manifest_sha256' => $this->manifestSha256($verification),
            'metrics' => $safeMetrics,
        ];
        foreach ($evidence['restore_tests'] as $existing) {
            if (($existing['evidence_id'] ?? null) === $evidenceId) {
                if (($existing['status'] ?? null) === 'SUCCESS'
                    && ($existing['backup_id'] ?? null) === $event['backup_id']
                    && ($existing['manifest_sha256'] ?? null) === $event['manifest_sha256']
                    && ($existing['metrics'] ?? null) === $event['metrics']) {
                    return ['status' => 'SUCCESS', 'evidence_id' => $evidenceId, 'idempotent' => true];
                }
                throw new RecoveryEvidenceException('A conflicting restore evidence ID already exists.');
            }
        }
        $evidence['restore_tests'][] = $event;
        $this->write($verification, $evidence);

        return ['status' => 'SUCCESS', 'evidence_id' => $evidenceId, 'idempotent' => false];
    }

    /** @return array<string,mixed> */
    public function inspect(BackupVerification $verification): array
    {
        if (! $verification->valid || $verification->manifest === null || $verification->root === null) {
            return ['status' => 'INVALID', 'code' => 'BACKUP_INVALID'];
        }
        $path = $verification->root.DIRECTORY_SEPARATOR.'recovery-evidence.json';
        if (! is_file($path) && ! is_link($path)) {
            return ['status' => 'MISSING'];
        }
        try {
            $payload = $this->load($verification);

            return [
                'status' => 'VALID',
                'off_server_confirmation' => $payload['off_server_confirmation'],
                'restore_tests' => $payload['restore_tests'],
            ];
        } catch (Throwable) {
            return ['status' => 'INVALID', 'code' => 'EVIDENCE_INTEGRITY_INVALID'];
        }
    }

    private function verifiedProductionBackup(string $backupPath): BackupVerification
    {
        $verification = $this->verifier->verify($backupPath);
        $this->assertVerifiedProductionBackup($verification);

        return $verification;
    }

    private function assertVerifiedProductionBackup(BackupVerification $verification): void
    {
        if (! $verification->valid || $verification->manifest === null || $verification->root === null
            || ($verification->manifest['status'] ?? null) !== 'COMPLETE'
            || ($verification->manifest['environment'] ?? null) !== 'production') {
            throw new RecoveryEvidenceException('Recovery evidence requires a verified production COMPLETE backup.');
        }
    }

    private function assertEvidenceId(string $evidenceId): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{2,127}\z/', $evidenceId) !== 1) {
            throw new RecoveryEvidenceException('Evidence ID must be a non-secret incident or change identifier.');
        }
    }

    /** @param array<string,mixed> $metrics */
    private function assertSafeMetrics(array $metrics): void
    {
        if (array_keys($metrics) !== ['critical_table_counts', 'schema_fingerprint', 'private_file_count', 'counts_fingerprint']
            || ! is_array($metrics['critical_table_counts'])
            || array_keys($metrics['critical_table_counts']) !== (array) config('backups.critical_tables')
            || collect($metrics['critical_table_counts'])->contains(fn (mixed $count): bool => ! is_int($count) || $count < 0)
            || ! is_string($metrics['schema_fingerprint']) || preg_match('/\A[0-9a-f]{64}\z/', $metrics['schema_fingerprint']) !== 1
            || ! is_int($metrics['private_file_count']) || $metrics['private_file_count'] < 0
            || ! is_string($metrics['counts_fingerprint']) || preg_match('/\A[0-9a-f]{64}\z/', $metrics['counts_fingerprint']) !== 1) {
            throw new RecoveryEvidenceException('Restore evidence metrics are invalid.');
        }
        $expected = $metrics;
        unset($expected['counts_fingerprint']);
        if (! hash_equals(hash('sha256', json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), $metrics['counts_fingerprint'])) {
            throw new RecoveryEvidenceException('Restore evidence metrics fingerprint is invalid.');
        }
    }

    /** @return array<string,mixed> */
    private function loadOrCreate(BackupVerification $verification): array
    {
        $path = $verification->root.DIRECTORY_SEPARATOR.'recovery-evidence.json';
        if (is_file($path) || is_link($path)) {
            return $this->load($verification);
        }

        return [
            'format_version' => self::FORMAT_VERSION,
            'backup_id' => $verification->manifest['backup_id'],
            'manifest_sha256' => $this->manifestSha256($verification),
            'environment' => 'production',
            'off_server_confirmation' => null,
            'restore_tests' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function load(BackupVerification $verification): array
    {
        $path = $verification->root.DIRECTORY_SEPARATOR.'recovery-evidence.json';
        if (is_link($path) || ! is_file($path)) {
            throw new RecoveryEvidenceException('Recovery evidence sidecar is unavailable or unsafe.');
        }
        $outer = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($outer) || array_keys($outer) !== ['format_version', 'backup_id', 'manifest_sha256', 'environment', 'sealed_evidence']
            || ! is_string($outer['sealed_evidence'] ?? null)) {
            throw new RecoveryEvidenceException('Recovery evidence envelope is invalid.');
        }
        $payload = json_decode(Crypt::decryptString($outer['sealed_evidence']), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)
            || array_keys($payload) !== ['format_version', 'backup_id', 'manifest_sha256', 'environment', 'off_server_confirmation', 'restore_tests']
            || ($payload['format_version'] ?? null) !== self::FORMAT_VERSION
            || ($payload['backup_id'] ?? null) !== $verification->manifest['backup_id']
            || ($payload['manifest_sha256'] ?? null) !== $this->manifestSha256($verification)
            || ($payload['environment'] ?? null) !== 'production'
            || ($outer['format_version'] ?? null) !== $payload['format_version']
            || ($outer['backup_id'] ?? null) !== $payload['backup_id']
            || ($outer['manifest_sha256'] ?? null) !== $payload['manifest_sha256']
            || ($outer['environment'] ?? null) !== $payload['environment']
            || ! array_key_exists('off_server_confirmation', $payload)
            || ! is_array($payload['restore_tests'] ?? null)) {
            throw new RecoveryEvidenceException('Recovery evidence binding is invalid.');
        }
        $this->assertPayloadEntries($payload);

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function assertPayloadEntries(array $payload): void
    {
        $confirmation = $payload['off_server_confirmation'];
        if ($confirmation !== null) {
            if (! is_array($confirmation)
                || array_keys($confirmation) !== ['evidence_id', 'confirmed_at_utc', 'operator_attested_full_copy_verified']
                || ! is_string($confirmation['evidence_id'] ?? null)
                || ! is_string($confirmation['confirmed_at_utc'] ?? null)
                || ($confirmation['operator_attested_full_copy_verified'] ?? null) !== true) {
                throw new RecoveryEvidenceException('Off-server recovery evidence is invalid.');
            }
            $this->assertEvidenceId($confirmation['evidence_id']);
            $this->assertUtcTimestamp($confirmation['confirmed_at_utc']);
        }

        foreach ($payload['restore_tests'] as $event) {
            if (! is_array($event)
                || array_keys($event) !== ['status', 'evidence_id', 'completed_at_utc', 'duration_seconds', 'backup_id', 'manifest_sha256', 'metrics']
                || ($event['status'] ?? null) !== 'SUCCESS'
                || ! is_string($event['evidence_id'] ?? null)
                || ! is_string($event['completed_at_utc'] ?? null)
                || ! is_numeric($event['duration_seconds'] ?? null)
                || (float) $event['duration_seconds'] < 0
                || ($event['backup_id'] ?? null) !== $payload['backup_id']
                || ($event['manifest_sha256'] ?? null) !== $payload['manifest_sha256']
                || ! is_array($event['metrics'] ?? null)) {
                throw new RecoveryEvidenceException('Restore recovery evidence is invalid.');
            }
            $this->assertEvidenceId($event['evidence_id']);
            $this->assertUtcTimestamp($event['completed_at_utc']);
            $this->assertSafeMetrics($event['metrics']);
        }
    }

    private function assertUtcTimestamp(string $timestamp): void
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $timestamp, new \DateTimeZone('UTC'));
        if ($parsed === false || $parsed->format('Y-m-d\TH:i:s\Z') !== $timestamp) {
            throw new RecoveryEvidenceException('Recovery evidence timestamp is invalid.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function write(BackupVerification $verification, array $payload): void
    {
        $root = $verification->root;
        $path = $root.DIRECTORY_SEPARATOR.'recovery-evidence.json';
        $temporary = $root.DIRECTORY_SEPARATOR.'.recovery-evidence-'.Str::uuid().'.tmp';
        $outer = [
            'format_version' => self::FORMAT_VERSION,
            'backup_id' => $payload['backup_id'],
            'manifest_sha256' => $payload['manifest_sha256'],
            'environment' => 'production',
            'sealed_evidence' => Crypt::encryptString((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
        ];
        try {
            $json = json_encode($outer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            if (file_put_contents($temporary, $json, LOCK_EX) === false || ! chmod($temporary, 0600)) {
                throw new RecoveryEvidenceException('Unable to write protected recovery evidence.');
            }
            if ($this->isTestingProcess() && config('backups.testing_evidence_fail_before_rename')) {
                throw new RecoveryEvidenceException('Simulated recovery evidence publication failure.');
            }
            if (! hash_equals((string) $payload['manifest_sha256'], $this->manifestSha256($verification))) {
                throw new RecoveryEvidenceException('The backup manifest changed during evidence publication.');
            }
            if (! rename($temporary, $path)) {
                throw new RecoveryEvidenceException('Unable to publish recovery evidence atomically.');
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function manifestSha256(BackupVerification $verification): string
    {
        return hash_file('sha256', $verification->root.DIRECTORY_SEPARATOR.'manifest.json');
    }

    /** @param array<string,mixed> $confirmation
     * @return array<string,mixed>
     */
    private function offServerResult(BackupVerification $verification, array $confirmation, bool $idempotent): array
    {
        return [
            'status' => 'OK',
            'backup_id' => $verification->manifest['backup_id'],
            'evidence_id' => $confirmation['evidence_id'],
            'confirmed_at_utc' => $confirmation['confirmed_at_utc'],
            'idempotent' => $idempotent,
        ];
    }

    private function isTestingProcess(): bool
    {
        return ($_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? null) === 'testing';
    }
}
