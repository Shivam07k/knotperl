<?php

namespace App\Console\Commands;

use App\Services\CMS\DataValidationService;
use App\Services\CMS\PublishingService;
use Illuminate\Console\Command;
use RuntimeException;

class ValidateWebsiteCommand extends Command
{
    protected $signature = 'cms:validate {--json : Print raw JSON report}';

    protected $description = 'Validate the content file without publishing';

    public function handle(PublishingService $publishing, DataValidationService $validator): int
    {
        try {
            $report = $publishing->validate();
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['ok'] ? self::SUCCESS : self::FAILURE;
        }

        foreach ($report['schema']['messages'] as $message) {
            $this->warn($message);
        }
        $this->line($validator->formatReport($report['validation']));
        $this->info($report['ok'] ? 'Validation passed.' : 'Validation failed; the live site was not touched.');

        return $report['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
