<?php

namespace Tests\Unit;

use RuntimeException;
use Tests\Support\IsolatedStorageSandbox;
use Tests\TestCase;

class IsolatedStorageSandboxTest extends TestCase
{
    public function test_successful_test_storage_is_removed_in_finally(): void
    {
        $sandbox = IsolatedStorageSandbox::create();
        $root = $sandbox->root();

        try {
            file_put_contents($sandbox->diskRoot('private').'/success.pdf', '%PDF-1.4');
            self::assertFileExists($sandbox->diskRoot('private').'/success.pdf');
        } finally {
            $sandbox->cleanup();
        }

        self::assertDirectoryDoesNotExist($root);
    }

    public function test_storage_rollback_failure_still_removes_only_its_unique_sandbox(): void
    {
        $sandbox = IsolatedStorageSandbox::create();
        $root = $sandbox->root();

        try {
            try {
                file_put_contents($sandbox->diskRoot('private').'/rollback.pdf', '%PDF-1.4');
                throw new RuntimeException('simulated storage rollback failure');
            } catch (RuntimeException $exception) {
                self::assertSame('simulated storage rollback failure', $exception->getMessage());
            }
        } finally {
            $sandbox->cleanup();
        }

        self::assertDirectoryDoesNotExist($root);
    }
}
