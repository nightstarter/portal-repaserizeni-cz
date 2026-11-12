<?php

namespace Tests\Unit;

use App\Application\Security\DatabaseCommandGuard;
use RuntimeException;
use Tests\TestCase;

class DatabaseCommandGuardTest extends TestCase
{
    public function test_production_blocks_every_destructive_command_even_with_an_isolated_connection(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');

        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'] as $command) {
            try {
                app(DatabaseCommandGuard::class)->assertCommandAllowed($command);
                self::fail("Expected {$command} to be blocked.");
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('blocked in production', $exception->getMessage());
                self::assertStringContainsString('--force cannot override', $exception->getMessage());
            }
        }
    }

    public function test_testing_allows_only_sqlite_memory_by_default(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.driver', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.url', null);
        config()->set('database.allow_destructive_test_database', false);

        app(DatabaseCommandGuard::class)->assertCommandAllowed('migrate:fresh');

        self::addToAssertionCount(1);
    }

    public function test_testing_always_rejects_production_database_name_without_exposing_password(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.database', 'portal_repaserizeni');
        config()->set('database.connections.mysql.password', 'must-not-appear');
        config()->set('database.allow_destructive_test_database', true);

        try {
            app(DatabaseCommandGuard::class)->assertCommandAllowed('db:wipe');
            self::fail('Expected production database name to be blocked.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Production database names are never allowed', $exception->getMessage());
            self::assertStringNotContainsString('must-not-appear', $exception->getMessage());
        }
    }

    public function test_separate_server_database_requires_explicit_flag_and_test_only_name(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'testing');
        config()->set('database.default', 'mysql');
        config()->set('database.connections.mysql.database', 'portal_repaserizeni_testing');
        config()->set('database.connections.mysql.url', null);
        config()->set('database.allow_destructive_test_database', false);

        try {
            app(DatabaseCommandGuard::class)->assertCommandAllowed('migrate:reset');
            self::fail('Expected explicit opt-in requirement.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not isolated', $exception->getMessage());
        }

        config()->set('database.allow_destructive_test_database', true);
        app(DatabaseCommandGuard::class)->assertCommandAllowed('migrate:reset');
        self::addToAssertionCount(1);
    }

    public function test_non_destructive_commands_are_not_intercepted(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');

        app(DatabaseCommandGuard::class)->assertCommandAllowed('migrate:status');
        app(DatabaseCommandGuard::class)->assertCommandAllowed('test');

        self::addToAssertionCount(2);
    }
}
