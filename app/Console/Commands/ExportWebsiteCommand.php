<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class ExportWebsiteCommand extends Command
{
    protected $signature = 'cms:export {--path=site : Repository directory that GitHub Actions deploys}';

    protected $description = 'Copy the current generated site into the deploy directory';

    public function handle(): int
    {
        $source = config('publishing.paths.current');
        if (! is_dir($source)) {
            $this->error('No live build exists. Run cms:publish --no-deploy first.');

            return self::FAILURE;
        }
        $target = base_path(trim((string) $this->option('path'), '/'));
        $files = new Filesystem();
        $files->ensureDirectoryExists($target);
        foreach ($files->allFiles($source) as $file) {
            $relative = substr($file->getPathname(), strlen($source) + 1);
            $destination = $target.'/'.$relative;
            $files->ensureDirectoryExists(dirname($destination));
            $files->copy($file->getPathname(), $destination);
        }
        $this->info('Exported to '.$target.'. Commit that directory to trigger deployment.');

        return self::SUCCESS;
    }
}
