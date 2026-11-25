<?php

namespace App\Backup;

use RuntimeException;

final class BackupPathGuard
{
    public function newBackupTarget(string $path): string
    {
        if (! $this->absolute($path) || $path === DIRECTORY_SEPARATOR || file_exists($path) || is_link($path)) {
            throw new RuntimeException('Backup target must be a new absolute non-root path.');
        }
        $inputParent = rtrim(dirname($path), DIRECTORY_SEPARATOR);
        $parent = realpath($inputParent);
        if ($parent === false || ! is_dir($parent) || is_link($inputParent) || $parent !== $inputParent
            || in_array(basename($path), ['.', '..'], true)) {
            throw new RuntimeException('Backup target parent must be a canonical non-symlink directory.');
        }
        if ($parent === realpath(sys_get_temp_dir())
            && preg_match('/\Aportal-backup-[A-Za-z0-9._-]{8,}\z/', basename($path)) !== 1) {
            throw new RuntimeException('A backup under the system temporary directory requires a unique portal-backup-* subdirectory.');
        }
        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->rejectProtected($candidate);

        return $candidate;
    }

    public function backupRoot(string $path): string
    {
        if (! $this->absolute($path) || $path === DIRECTORY_SEPARATOR || is_link($path)) {
            throw new RuntimeException('Backup root must be an absolute non-root non-symlink directory.');
        }
        $resolved = realpath($path);
        if ($resolved === false || ! is_dir($resolved) || $resolved !== rtrim($path, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Backup root must be an existing canonical directory.');
        }
        $this->rejectProtected($resolved);

        return $resolved;
    }

    public function existingBackup(string $path): string
    {
        if (! $this->absolute($path) || is_link($path)) {
            throw new RuntimeException('Backup path must be an absolute, non-symlink path.');
        }

        $resolved = realpath($path);
        if ($resolved === false) {
            throw new RuntimeException('Backup path does not exist.');
        }

        $root = is_dir($resolved) ? $resolved : dirname($resolved);
        $this->rejectProtected($root);

        return $resolved;
    }

    /** @return array{path:string,created:bool} */
    public function emptyRestoreTarget(string $path): array
    {
        if (! $this->absolute($path) || $path === DIRECTORY_SEPARATOR) {
            throw new RuntimeException('Restore target must be an absolute non-root path.');
        }
        if (file_exists($path) && is_link($path)) {
            throw new RuntimeException('Restore target must not be a symlink.');
        }

        $parent = realpath(dirname($path));
        if ($parent === false || is_link(dirname($path)) || in_array(basename($path), ['.', '..'], true)) {
            throw new RuntimeException('Restore target parent must be a canonical directory.');
        }
        $candidate = $parent.DIRECTORY_SEPARATOR.basename($path);
        $this->rejectProtected($candidate);

        $created = false;
        if (! file_exists($candidate)) {
            if (! mkdir($candidate, 0700)) {
                throw new RuntimeException('Unable to create restore target.');
            }
            $created = true;
        }
        $resolved = realpath($candidate);
        if ($resolved === false || ! is_dir($resolved)) {
            throw new RuntimeException('Restore target is not a directory.');
        }
        if ((scandir($resolved) ?: []) !== ['.', '..']) {
            throw new RuntimeException('Restore target must be empty.');
        }

        return ['path' => $resolved, 'created' => $created];
    }

    public function isWithin(string $path, string $root): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        return $path === $root || str_starts_with($path.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR);
    }

    private function rejectProtected(string $candidate): void
    {
        $candidate = rtrim($candidate, DIRECTORY_SEPARATOR);
        $protected = array_filter([
            realpath(base_path()),
            realpath(public_path()),
            realpath((string) config('filesystems.disks.local.root')),
        ]);
        foreach ($protected as $path) {
            if ($this->isWithin($candidate, $path) || $this->isWithin($path, $candidate)) {
                throw new RuntimeException('Path overlaps a protected application location.');
            }
        }
    }

    private function absolute(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/\A[A-Za-z]:[\\\\\/]/', $path) === 1;
    }
}
