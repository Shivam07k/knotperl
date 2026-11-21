<?php

namespace App\Console\Commands;

use App\Services\Cloudflare\ImageManagerService;
use Illuminate\Console\Command;

class CleanupImagesCommand extends Command
{
    protected $signature = 'cms:cleanup-images';

    protected $description = 'Delete images whose retention period has expired';

    public function handle(ImageManagerService $images): int
    {
        $result = $images->processDeletions();
        foreach ($result['deleted'] as $key) {
            $this->line('Deleted: '.$key);
        }
        $this->info($result['waiting'].' image(s) still waiting.');

        return self::SUCCESS;
    }
}
