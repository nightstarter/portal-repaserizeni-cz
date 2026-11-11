<?php

declare(strict_types=1);

$roots = ['app', 'bootstrap', 'config', 'database', 'routes', 'tests', 'resources/lang'];
$failed = false;

foreach ($roots as $root) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../'.$root));

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $command = escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()).' 2>&1';
        exec($command, $output, $status);
        echo implode(PHP_EOL, $output).PHP_EOL;
        $output = [];
        $failed = $failed || $status !== 0;
    }
}

exit($failed ? 1 : 0);
