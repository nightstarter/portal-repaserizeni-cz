<?php

return [
    'enabled' => (bool) env('DISCUSSION_EMAIL_ENABLED', false),
    'max_attempts' => (int) env('DISCUSSION_EMAIL_MAX_ATTEMPTS', 5),
    'batch_size' => (int) env('DISCUSSION_EMAIL_BATCH_SIZE', 50),
    'retry_minutes' => (int) env('DISCUSSION_EMAIL_RETRY_MINUTES', 5),
    'lock_minutes' => (int) env('DISCUSSION_EMAIL_LOCK_MINUTES', 10),
];
