<?php

return [
    'format_version' => 1,
    'dump_binary' => env('BACKUP_DUMP_BINARY', 'mariadb-dump'),
    'dump_timeout_seconds' => (int) env('BACKUP_DUMP_TIMEOUT_SECONDS', 3600),
    'diagnose_timeout_seconds' => (int) env('BACKUP_DIAGNOSE_TIMEOUT_SECONDS', 60),
    'diagnose_parent' => env('BACKUP_DIAGNOSE_PARENT', sys_get_temp_dir()),
    'request_drain_seconds' => (int) env('BACKUP_REQUEST_DRAIN_SECONDS', 10),
    'latest_manifest' => env('BACKUP_LATEST_MANIFEST'),
    'restore_evidence_manifest' => env('BACKUP_RESTORE_EVIDENCE_MANIFEST'),
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
    'testing_tamper_after_manifest' => false,
    'testing_fail_phase' => null,
    'testing_git_commit' => null,
    'testing_database_configuration' => null,
    'testing_database_objects' => null,
    'testing_source_identity_sha256' => null,
    'testing_evidence_fail_before_rename' => false,
];
