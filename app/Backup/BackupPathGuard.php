<?php

namespace App\Backup;

use RuntimeException;

final class BackupPathGuard
{
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
