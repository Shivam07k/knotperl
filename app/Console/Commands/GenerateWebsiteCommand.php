<?php

namespace App\Console\Commands;

use App\Services\CMS\PublishingService;
use Illuminate\Console\Command;
use RuntimeException;

class GenerateWebsiteCommand extends Command
{
    protected $signature = 'cms:generate {--preview : Generate a draft instead of replacing the live directory}';

    protected $description = 'Generate the static website from the content file';

    public function handle(PublishingService $publishing): int
    {
        try {
            $result = $this->option('preview') ? $publishing->preview() : $publishing->publish(false);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Generated: '.($result['path'] ?? config('publishing.paths.current')));
        $this->line('Files: '.($result['files'] ?? '-'));

        return self::SUCCESS;
    }
}
