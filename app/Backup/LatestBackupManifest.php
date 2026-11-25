<?php

namespace App\Backup;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class LatestBackupManifest
{
    public function __construct(
        private readonly BackupPathGuard $paths,
        private readonly BackupManifestVerifier $verifier,
    ) {}

    public function resolve(): ?string
    {
        $pointer = config('backups.latest_pointer');
        if (! is_string($pointer) || $pointer === '' || ! file_exists($pointer)) {
            $fallback = config('backups.latest_manifest');

            return is_string($fallback) && $fallback !== '' ? $fallback : null;
        }
        $this->pointerPath($pointer);
        if (is_link($pointer) || ! is_file($pointer)) {
            throw new RuntimeException('Latest backup pointer is not a regular file.');
        }
        try {
            $payload = json_decode((string) file_get_contents($pointer), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Latest backup pointer is invalid.');
        }
        $manifest = is_array($payload) ? ($payload['manifest'] ?? null) : null;
        if (($payload['format_version'] ?? null) !== 1 || ! is_string($manifest) || $manifest === '') {
            throw new RuntimeException('Latest backup pointer is invalid.');
        }
        $root = $this->paths->backupRoot($this->configuredRoot());
        $resolved = realpath($manifest);
        if ($resolved === false || ! is_file($resolved) || is_link($manifest)
            || ! $this->paths->isWithin($resolved, $root) || basename($resolved) !== 'manifest.json') {
            throw new RuntimeException('Latest backup pointer target is invalid.');
        }

        return $resolved;
    }

    /** Publish only a verified COMPLETE package. */
    public function publish(string $target): void
    {
        $verification = $this->verifier->verify($target);
        $manifest = $verification->manifest;
        if (! $verification->valid || ! is_array($manifest) || ($manifest['status'] ?? null) !== 'COMPLETE'
            || ! in_array($manifest['environment'] ?? null, ['production', 'testing'], true)) {
            throw new RuntimeException('Only a verified COMPLETE backup can become latest.');
        }
        $pointer = config('backups.latest_pointer');
        if (! is_string($pointer) || $pointer === '') {
            throw new RuntimeException('Latest backup pointer is not configured.');
        }
        $pointer = $this->pointerPath($pointer);
        $root = $this->paths->backupRoot($this->configuredRoot());
        $manifestPath = realpath($target.DIRECTORY_SEPARATOR.'manifest.json');
        if ($manifestPath === false || ! $this->paths->isWithin($manifestPath, $root)) {
            throw new RuntimeException('Verified backup is outside the configured backup root.');
        }
        $temporary = dirname($pointer).DIRECTORY_SEPARATOR.'.'.basename($pointer).'.tmp-'.Str::uuid();
        $payload = json_encode([
            'format_version' => 1,
            'manifest' => $manifestPath,
            'backup_id' => $manifest['backup_id'],
            'created_at_utc' => $manifest['created_at_utc'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        try {
            if (file_put_contents($temporary, $payload, LOCK_EX) === false || ! chmod($temporary, 0600)) {
                throw new RuntimeException('Unable to write latest backup pointer.');
            }
            if (app()->environment('testing') && config('backups.testing_pointer_fail_before_rename')) {
                throw new RuntimeException('Pointer publication failed.');
            }
            if (! rename($temporary, $pointer)) {
                throw new RuntimeException('Unable to publish latest backup pointer atomically.');
            }
        } finally {
            if (file_exists($temporary) || is_link($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function configuredRoot(): string
    {
        $root = config('backups.root');
        if (! is_string($root) || $root === '') {
            throw new RuntimeException('Backup root is not configured.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR);
    }

    private function pointerPath(string $pointer): string
    {
        if (! str_starts_with($pointer, DIRECTORY_SEPARATOR) || $pointer === DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Latest backup pointer path must be absolute.');
        }
        $root = $this->paths->backupRoot($this->configuredRoot());
        $parent = realpath(dirname($pointer));
        if ($parent === false || is_link(dirname($pointer)) || ! $this->paths->isWithin($parent, $root)) {
            throw new RuntimeException('Latest backup pointer must be stored inside the backup root.');
        }

        return $parent.DIRECTORY_SEPARATOR.basename($pointer);
    }
}
