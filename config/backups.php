<?php

return [
    'format_version' => 1,
    'latest_manifest' => env('BACKUP_LATEST_MANIFEST'),
    'maximum_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'restore_test_maximum_age_days' => (int) env('BACKUP_RESTORE_TEST_MAX_AGE_DAYS', 100),
    'production_database' => [
        'host' => env('BACKUP_PRODUCTION_DB_HOST'),
        'port' => env('BACKUP_PRODUCTION_DB_PORT'),
        'database' => env('BACKUP_PRODUCTION_DB_DATABASE'),
    ],
    'critical_tables' => [
        'organizations',
        'users',
        'orders',
        'items',
        'process_threads',
        'shipments',
        'documents',
        'messages',
        'message_attachments',
    ],
    // Test-only failure seam; ignored unless the process environment is testing.
    'testing_fail_after_copied_parts' => null,
];
