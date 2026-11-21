<?php

return [
    'default' => env('FILESYSTEM_DISK', 'local'),
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
        ],
        'generated' => [
            'driver' => 'local',
            'root' => storage_path('app/generated'),
        ],
        'temporary' => [
            'driver' => 'local',
            'root' => storage_path('app/temporary'),
        ],
    ],
];
