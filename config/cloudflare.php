<?php

$accountId = env('CLOUDFLARE_ACCOUNT_ID');

return [
    'account_id' => $accountId,
    'r2' => [
        'access_key_id' => env('CLOUDFLARE_R2_ACCESS_KEY_ID'),
        'secret_access_key' => env('CLOUDFLARE_R2_SECRET_ACCESS_KEY'),
        'bucket' => env('CLOUDFLARE_R2_BUCKET'),
        'endpoint' => $accountId ? "https://{$accountId}.r2.cloudflarestorage.com" : null,
        'region' => 'auto',
        'public_url' => rtrim((string) env('CLOUDFLARE_R2_PUBLIC_URL'), '/'),
    ],
    'pages' => [
        'api_token' => env('CLOUDFLARE_API_TOKEN'),
        'project' => env('CLOUDFLARE_PAGES_PROJECT'),
        'account_id' => $accountId,
    ],
    'images' => [
        'max_bytes' => (int) env('IMAGE_MAX_BYTES', 8 * 1024 * 1024),
        'allowed_mime' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'quality' => (int) env('IMAGE_QUALITY', 82),
        'widths' => [480, 960, 1440],
        'thumbnail' => 256,
        'retention_days' => (int) env('IMAGE_RETENTION_DAYS', 30),
        'deletion_queue' => storage_path('app/private/image-deletions.json'),
    ],
];
