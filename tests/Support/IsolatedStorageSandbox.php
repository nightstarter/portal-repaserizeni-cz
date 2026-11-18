<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

final class IsolatedStorageSandbox
{
    private const PREFIX = 'portal-phase4b-documents-';

    private function __construct(
        private readonly string $root,
        private readonly string $systemTemp,
    ) {}

    public static function create(): self
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing to create a test storage sandbox outside the testing environment.');
        }

        $systemTemp = realpath(sys_get_temp_dir());
        if ($systemTemp === false) {
            throw new RuntimeException('Unable to resolve the system temporary directory.');
        }

        $root = $systemTemp.DIRECTORY_SEPARATOR.self::PREFIX.Str::uuid();
        if (! mkdir($root, 0700) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create the test storage sandbox.');
        }

        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false || ! str_starts_with($resolvedRoot, $systemTemp.DIRECTORY_SEPARATOR.self::PREFIX)) {
            throw new RuntimeException('The test storage sandbox resolved outside the approved system temporary directory.');
        }

        return new self($resolvedRoot, $systemTemp);
    }

    public function root(): string
    {
        return $this->root;
    }

    public function diskRoot(string $disk): string
    {
        if (! preg_match('/\A[a-z][a-z0-9-]*\z/', $disk)) {
            throw new RuntimeException('Invalid isolated test disk name.');
        }

        $root = $this->root.DIRECTORY_SEPARATOR.$disk;
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Unable to create an isolated test disk.');
        }

        return $root;
    }

    public function temporaryFile(string $prefix): string
    {
        $directory = $this->diskRoot('uploads');
        $path = tempnam($directory, $prefix);
        if ($path === false) {
            throw new RuntimeException('Unable to create an isolated temporary upload.');
        }

        return $path;
    }

    public function cleanup(): void
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('Refusing to clean a test storage sandbox outside the testing environment.');
        }

        if (! is_dir($this->root)) {
            return;
        }

        $resolvedRoot = realpath($this->root);
        $productionStorage = realpath(storage_path('app/private'));
        $approvedPrefix = $this->systemTemp.DIRECTORY_SEPARATOR.self::PREFIX;

        if ($resolvedRoot === false
            || $resolvedRoot !== $this->root
            || ! str_starts_with($resolvedRoot, $approvedPrefix)
            || ($productionStorage !== false && $resolvedRoot === $productionStorage)) {
            throw new RuntimeException('Refusing to clean an unverified or production storage path.');
        }

        File::deleteDirectory($resolvedRoot);

        if (file_exists($resolvedRoot)) {
            throw new RuntimeException('The isolated test storage sandbox was not fully removed.');
        }
    }
}
