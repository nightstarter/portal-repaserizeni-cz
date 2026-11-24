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
    'monitoring' => [
        'enabled' => (bool) env('OPS_MONITOR_ENABLED', false),
        'lock_seconds' => (int) env('OPS_MONITOR_LOCK_SECONDS', 240),
        'run_stale_minutes' => (int) env('OPS_MONITOR_STALE_MINUTES', 15),
        'scheduler_stale_minutes' => (int) env('OPS_SCHEDULER_STALE_MINUTES', 10),
        'outbox_lock_stale_minutes' => (int) env('OPS_OUTBOX_LOCK_STALE_MINUTES', 15),
        'error_after_occurrences' => (int) env('OPS_ALERT_ERROR_AFTER_OCCURRENCES', 3),
        'critical_after_minutes' => (int) env('OPS_ALERT_CRITICAL_AFTER_MINUTES', 30),
        'cooldown_minutes' => (int) env('OPS_ALERT_COOLDOWN_MINUTES', 30),
        'email_enabled' => (bool) env('OPS_ALERT_EMAIL_ENABLED', false),
        'email_recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('OPS_ALERT_RECIPIENTS', ''))))),
        'tls_enabled' => (bool) env('OPS_TLS_CHECK_ENABLED', false),
        'tls_host' => env('OPS_TLS_HOST'),
        'tls_port' => (int) env('OPS_TLS_PORT', 443),
        'tls_timeout_seconds' => (int) env('OPS_TLS_TIMEOUT_SECONDS', 5),
        'tls_warn_days' => (int) env('OPS_TLS_WARN_DAYS', 30),
        'tls_error_days' => (int) env('OPS_TLS_ERROR_DAYS', 7),
    ],
];
