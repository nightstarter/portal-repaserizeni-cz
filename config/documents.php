<?php

return [
    'disk' => env('DOCUMENTS_DISK', 'local'),
    'max_kilobytes' => (int) env('DOCUMENTS_MAX_KILOBYTES', 10240),
    'allowed' => [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ],
];
