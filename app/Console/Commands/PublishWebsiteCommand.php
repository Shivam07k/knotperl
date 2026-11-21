<?php

namespace App\Console\Commands;

use App\Services\CMS\PublishingService;
use Illuminate\Console\Command;
use RuntimeException;

class PublishWebsiteCommand extends Command
{
    protected $signature = 'cms:publish {--no-deploy : Swap the local live directory without contacting the deployment target}';

    protected $description = 'Validate, generate and publish the website';

    public function handle(PublishingService $publishing): int
    {
        try {
            $result = $publishing->publish(! $this->option('no-deploy'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Published '.$result['id']);
        $this->line('Deployment: '.$result['deployment']['mode'].' / '.$result['deployment']['status']);

        return self::SUCCESS;
    }
}
