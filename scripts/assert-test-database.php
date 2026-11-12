<?php

use App\Application\Security\DatabaseCommandGuard;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';

$basePath = dirname(__DIR__);
$environmentFile = $basePath.'/.env.testing';

if (! is_file($environmentFile)) {
    fwrite(STDERR, "Refusing to run tests: .env.testing is missing.\n");
    exit(1);
}

if ((fileperms($environmentFile) & 0077) !== 0) {
    fwrite(STDERR, "Refusing to run tests: .env.testing must have permissions 600.\n");
    exit(1);
}

$app = require $basePath.'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();

try {
    $app->make(DatabaseCommandGuard::class)->assertTestingDatabaseSafe();
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, "Test database guard: isolated SQLite :memory: connection confirmed.\n");
