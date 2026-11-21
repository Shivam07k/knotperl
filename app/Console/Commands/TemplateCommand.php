<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class TemplateCommand extends Command
{
    protected $signature = 'cms:template';

    protected $description = 'List the sections and columns in the content template';

    public function handle(): int
    {
        $path = (string) config('cms.template_path');
        $this->line('Template: '.$path);
        $template = json_decode((string) file_get_contents($path), true);
        foreach ($template['tabs'] ?? [] as $tab) {
            $this->line(' - '.$tab['name'].' ('.count($tab['headers']).' columns)');
        }

        return self::SUCCESS;
    }
}
