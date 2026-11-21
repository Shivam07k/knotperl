<?php

namespace App\Console\Commands;

use App\Services\CMS\PublishingService;
use Illuminate\Console\Command;
use RuntimeException;

class RollbackWebsiteCommand extends Command
{
    protected $signature = 'cms:rollback {id? : Snapshot ID; defaults to the most recent backup}';

    protected $description = 'Restore a previous deployment snapshot';

    public function handle(PublishingService $publishing): int
    {
        try {
            $result = $publishing->rollback($this->argument('id'));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Rolled back: '.$result['id']);

        return self::SUCCESS;
    }
}
