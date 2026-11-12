<?php

namespace App\Application\Security;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use RuntimeException;

final class DatabaseCommandGuard
{
    private const DESTRUCTIVE_COMMANDS = [
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
        'db:wipe',
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Repository $config,
    ) {}

    public function assertCommandAllowed(?string $command): void
    {
        if (! in_array($command, self::DESTRUCTIVE_COMMANDS, true)) {
            return;
        }

        if ($this->app->environment('production')) {
            throw new RuntimeException(
                'Destructive database command blocked in production. --force cannot override this application safety policy.',
            );
        }

        if ($this->app->environment('testing')) {
            $this->assertTestingDatabaseSafe();
        }
    }

    public function assertTestingDatabaseSafe(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Test database safety check requires the testing environment.');
        }

        $connection = (string) $this->config->get('database.default');
        $driver = strtolower((string) $this->config->get("database.connections.{$connection}.driver"));
        $database = (string) $this->config->get("database.connections.{$connection}.database");
        $url = trim((string) $this->config->get("database.connections.{$connection}.url"));
        $normalizedDatabase = strtolower(trim($database));

        if ($normalizedDatabase === 'portal_repaserizeni') {
            throw new RuntimeException('Unsafe test database blocked. Production database names are never allowed in testing.');
        }

        if ($connection === 'sqlite' && $driver === 'sqlite' && $database === ':memory:' && $url === '') {
            return;
        }

        $explicitlyAllowed = (bool) $this->config->get('database.allow_destructive_test_database', false);
        $hasTestOnlyName = preg_match('/(^test[_-]|[_-]test(?:ing)?$)/i', basename($database)) === 1;

        if ($explicitlyAllowed && $database !== '' && $url === '' && $hasTestOnlyName) {
            return;
        }

        throw new RuntimeException(
            'Destructive database command blocked: the testing connection is not isolated. Use SQLite :memory: or an explicitly allowlisted test database.',
        );
    }
}
