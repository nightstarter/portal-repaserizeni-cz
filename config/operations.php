<?php

return [
    'preflight' => [
        'heartbeat_stale_minutes' => (int) env('OPS_HEARTBEAT_STALE_MINUTES', 15),
        'snapshot_stale_minutes' => (int) env('OPS_SNAPSHOT_STALE_MINUTES', 30),
        'minimum_free_disk_megabytes' => (int) env('OPS_MIN_FREE_DISK_MB', 1024),
        // Test-only seam. It is ignored outside APP_ENV=testing.
        'disk_free_bytes_override' => null,
        'connector_php_version' => env('POHODA_CONNECTOR_PHP_VERSION', '7.4.33'),
        'connector_os_version' => env('POHODA_CONNECTOR_OS_VERSION', 'Windows Server 2012 R2'),
    ],
    'product_repricing' => env('PRODUCT_REPRICING', 'DEFERRED_EXTERNAL_SYSTEM'),
];
