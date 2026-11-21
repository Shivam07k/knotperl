<?php

return [
    'mode' => env('PUBLISH_MODE', 'manual'), // manual | notify | approval
    'strict' => (bool) env('PUBLISH_STRICT', true),
    'webhook_secret' => env('PUBLISH_WEBHOOK_SECRET'),
    'github' => [
        'repository' => env('GITHUB_REPOSITORY'),
        'token' => env('GITHUB_DEPLOY_TOKEN'),
        'workflow' => env('GITHUB_WORKFLOW', 'deploy.yml'),
        'branch' => env('GITHUB_BRANCH', 'main'),
    ],
    'paths' => [
        'generated' => storage_path('app/generated'),
        'drafts' => storage_path('app/generated/drafts'),
        'current' => storage_path('app/generated/current'),
        'artifact' => storage_path('app/generated/artifact.zip'),
        'logs' => storage_path('app/publishing-logs'),
        'backups' => storage_path('app/backups'),
    ],
    'keep_backups' => (int) env('PUBLISH_KEEP_BACKUPS', 5),
    'site_url' => env('LIVE_SITE_URL'),
];
