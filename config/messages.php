<?php

return [
    'disk' => env('MESSAGE_ATTACHMENTS_DISK', 'local'),
    'max_attachments' => (int) env('MESSAGE_ATTACHMENTS_MAX_COUNT', 5),
    'max_kilobytes' => (int) env('MESSAGE_ATTACHMENTS_MAX_KILOBYTES', 10240),
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ],
];
