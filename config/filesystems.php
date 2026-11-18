<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'visibility' => 'private',
            'directory_visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0644, 'private' => 0660],
                'dir' => ['public' => 0755, 'private' => 0770],
            ],
        ],
        'public' => ['driver' => 'local', 'root' => storage_path('app/public'), 'url' => env('APP_URL').'/storage', 'visibility' => 'public', 'throw' => false],
    ],
];
