<?php

namespace App\Backup;

use App\Backup\Contracts\PrivateStorageArchiver;
use FilesystemIterator;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class PharPrivateStorageArchiver implements PrivateStorageArchiver
{
    public function __construct(private readonly PrivateStoragePolicy $policy) {}

    public function inventory(string $source): array
    {
        $root = realpath($source);
        if ($root === false || ! is_dir($root) || is_link($source)) {
            throw new RuntimeException('Private storage source is unavailable or unsafe.');
        }
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new RuntimeException('Private storage must not contain symlinks.');
            }
            if (! $file->isFile()) {
                continue;
            }
            $path = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            if ($this->policy->isExcludedOperationalArtifact($path)) {
                continue;
            }
            $entries[] = [
                'path' => $path,
                'size' => $file->getSize(),
                'sha256' => hash_file('sha256', $file->getPathname()),
                'mode' => '0'.substr(sprintf('%o', $file->getPerms()), -3),
            ];
        }
        usort($entries, fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return $entries;
    }

    public function archive(string $source, string $outputPath): array
    {
        $entries = $this->inventory($source);
        if (file_exists($outputPath)) {
            throw new RuntimeException('Private storage archive target already exists.');
        }
        try {
            $archive = new PharData($outputPath);
            $root = realpath($source);
            if ($entries === []) {
                $archive->addEmptyDir('.backup-empty');
            }
            foreach ($entries as $entry) {
                $archive->addFile($root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $entry['path']), $entry['path']);
            }
            unset($archive);
            if (! is_file($outputPath) || ! chmod($outputPath, 0600)) {
                throw new RuntimeException('Private storage archive was not created safely.');
            }
        } catch (\Throwable) {
            @unlink($outputPath);
            throw new RuntimeException('Private storage archival failed.');
        }

        return $entries;
    }
}
