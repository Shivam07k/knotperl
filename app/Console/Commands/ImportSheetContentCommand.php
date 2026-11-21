<?php

namespace App\Console\Commands;

use App\Services\CMS\JsonContentService;
use Illuminate\Console\Command;

class ImportSheetContentCommand extends Command
{
    protected $signature = 'cms:content:import {--out=storage/app/content.json}';

    protected $description = 'Export the current sheet content to a JSON file';

    public function handle(JsonContentService $sheets): int
    {
        $data = [];
        foreach ($sheets->sheetTitles() as $tab) {
            $data[$tab] = $sheets->readSheet($tab, true);
        }

        $path = (string) $this->option('out');
        if (! preg_match('#^(?:[A-Za-z]:[\\\\/]|/)#', $path)) {
            $path = base_path($path);
        }
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        $this->info('Imported '.count($data).' tabs to '.$path);

        return self::SUCCESS;
    }
}
