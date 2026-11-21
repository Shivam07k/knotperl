<?php

use App\Services\Cloudflare\R2StorageService;

if (! function_exists('r2')) {
    function r2(mixed $reference): string
    {
        return R2StorageService::url($reference);
    }
}
