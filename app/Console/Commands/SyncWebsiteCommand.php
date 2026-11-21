<?php

namespace App\Console\Commands;

use App\Services\CMS\JsonContentService;
use Illuminate\Console\Command;
use RuntimeException;

class SyncWebsiteCommand extends Command
{
    protected $signature = 'cms:sync';

    protected $description = 'Cache normalized content data without generating a site';

    public function handle(JsonContentService $sheets): int
    {
        try {
            $data = $sheets->readAll([], true);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $path = storage_path('app/generated/last-sync.json');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode([
            'synced_at' => now()->toIso8601String(),
            'counts' => array_map('count', $data),
        ], JSON_PRETTY_PRINT), LOCK_EX);

        foreach ($data as $tab => $rows) {
            $this->line(str_pad($tab, 16).count($rows).' row(s)');
        }
        $this->info('Cached to '.$path);

        return self::SUCCESS;
    }
}
