<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $environment = app()->environment();
        $connection = (string) config('database.default');
        $database = (string) config('database.connections.'.$connection.'.database');
        if ($environment !== 'testing' || $connection !== 'sqlite' || $database !== ':memory:') {
            throw new RuntimeException(sprintf(
                'Unsafe test environment rejected: APP_ENV=%s, DB_CONNECTION=%s, DB_DATABASE=%s.',
                $environment,
                $connection,
                $database,
            ));
        }
    }
}
