<?php

namespace App\Services\CMS;

use App\Services\Cloudflare\PagesDeploymentService;

use Illuminate\Filesystem\Filesystem;
use RuntimeException;
use ZipArchive;

class PublishingService
{
    public function __construct(
        private JsonContentService $sheets,
        private SpreadsheetSchemaService $schema,
        private DataValidationService $validator,
        private ContentBuilderService $builder,
        private StaticSiteGenerator $generator,
        private PagesDeploymentService $deployment,
    ) {}

    public function validate(): array
    {
        $tabs = $this->sheets->sheetTitles();
        $headers = [];
        foreach ($this->schema->tabNames() as $name) {
            if (in_array($name, $tabs, true)) {
                $headers[$name] = $this->sheets->headers($name);
            }
        }
        $schemaReport = $this->schema->verify($tabs, $headers);
        $data = $this->sheets->readAll();
        $validation = $this->validator->validate($data);

        return [
            'schema' => $schemaReport,
            'validation' => $validation,
            'ok' => $schemaReport['ok'] && $validation['ok'],
            'site' => $validation['data'],
        ];
    }

    public function preview(): array
    {
        $report = $this->validate();
        if (! $report['schema']['ok']) {
            throw new RuntimeException('Content schema is invalid: '.implode('; ', $report['schema']['messages']));
        }
        if (! $report['validation']['ok'] && (bool) config('publishing.strict')) {
            throw new RuntimeException($this->validator->formatReport($report['validation']));
        }

        $site = $this->builder->build($report['site']);
        $id = 'preview-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $target = config('publishing.paths.drafts').'/'.$id;
        $result = $this->generator->render($site, $target);
        $zip = $this->zip($target, config('publishing.paths.drafts').'/'.$id.'.zip');

        return [
            'id' => $id,
            'path' => $target,
            'zip' => $zip,
            'files' => $result['files'],
            'validation' => $report['validation'],
            'site' => $site,
        ];
    }

    public function publish(bool $deploy = true): array
    {
        if ($deploy && config('cms.local_mode')) {
            throw new RuntimeException('Real publishing is disabled in local fixture mode.');
        }

        $preview = $this->preview();
        $site = $preview['site'];
        $id = 'live-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
        $staging = config('publishing.paths.generated').'/staging-'.$id;
        $result = $this->generator->render($site, $staging);
        $artifact = config('publishing.paths.artifact');
        $this->zip($staging, $artifact);

        $deployment = ['mode' => 'none', 'status' => 'skipped'];
        if ($deploy) {
            try {
                $deployment = $this->deployment->deploy($artifact);
            } catch (\Throwable $exception) {
                $this->log([
                    'id' => $id,
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                    'at' => now()->toIso8601String(),
                ]);
                $this->removeDirectory($staging);
                throw new RuntimeException('Publishing failed; the live site was not changed. '.$exception->getMessage(), 0, $exception);
            }
        }

        $current = config('publishing.paths.current');
        $backupDir = config('publishing.paths.backups').'/'.$id;
        (new Filesystem())->ensureDirectoryExists(dirname($backupDir));
        if (is_dir($current)) {
            rename($current, $backupDir);
        }
        rename($staging, $current);
        $this->pruneBackups();

        $manifest = [
            'id' => $id,
            'published_at' => now()->toIso8601String(),
            'files' => $result['files'],
            'deployment' => $deployment,
            'checksum' => hash_file('sha256', $artifact),
            'validation' => [
                'errors' => count($preview['validation']['errors']),
                'warnings' => count($preview['validation']['warnings']),
            ],
        ];
        file_put_contents(config('publishing.paths.generated').'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT), LOCK_EX);
        $this->log(array_merge($manifest, ['status' => 'success']));

        return $manifest;
    }

    public function rollback(?string $id = null): array
    {
        if (config('cms.local_mode')) {
            throw new RuntimeException('Rollback is disabled in local fixture mode.');
        }

        $backups = array_values(array_filter(glob(config('publishing.paths.backups').'/*') ?: [], 'is_dir'));
        if ($backups === []) {
            throw new RuntimeException('There is no previous deployment to roll back to.');
        }
        rsort($backups);
        $target = $id ? config('publishing.paths.backups').'/'.basename($id) : $backups[0];
        if (! is_dir($target)) {
            throw new RuntimeException('That deployment snapshot does not exist.');
        }

        $current = config('publishing.paths.current');
        $swap = config('publishing.paths.generated').'/rollback-'.date('Ymd-His');
        if (is_dir($current)) {
            rename($current, $swap);
        }
        rename($target, $current);
        if (is_dir($swap)) {
            rename($swap, $target);
        }

        $artifact = config('publishing.paths.artifact');
        $this->zip($current, $artifact);
        $deployment = $this->deployment->deploy($artifact);
        $manifest = [
            'id' => 'rollback-'.basename($target),
            'published_at' => now()->toIso8601String(),
            'files' => 0,
            'deployment' => $deployment,
            'checksum' => hash_file('sha256', $artifact),
            'status' => 'rollback',
        ];
        file_put_contents(config('publishing.paths.generated').'/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT), LOCK_EX);
        $this->log($manifest);

        return $manifest;
    }

    public function status(): array
    {
        $path = config('publishing.paths.generated').'/manifest.json';
        $manifest = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];

        return [
            'manifest' => $manifest,
            'history' => $this->history(),
            'live_exists' => is_dir(config('publishing.paths.current')),
        ];
    }

    public function history(int $limit = 20): array
    {
        $path = config('publishing.paths.logs').'/publishing.jsonl';
        if (! is_file($path)) {
            return [];
        }
        $lines = array_filter(explode("\n", (string) file_get_contents($path)));
        $entries = array_map(static fn ($line): array => json_decode($line, true) ?: [], $lines);

        return array_slice(array_reverse($entries), 0, $limit);
    }

    private function zip(string $source, string $destination): string
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required to build a deployment artifact.');
        }
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the deployment artifact.');
        }
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), substr($file->getPathname(), strlen($source) + 1));
            }
        }
        $zip->close();

        return $destination;
    }

    private function pruneBackups(): void
    {
        $backups = glob(config('publishing.paths.backups').'/*') ?: [];
        usort($backups, static fn ($a, $b): int => filemtime($a) <=> filemtime($b));
        while (count($backups) > (int) config('publishing.keep_backups')) {
            $this->removeDirectory(array_shift($backups));
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_dir($path)) {
            (new Filesystem())->deleteDirectory($path);
        } elseif (is_file($path)) {
            unlink($path);
        }
    }

    private function log(array $entry): void
    {
        $dir = config('publishing.paths.logs');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        file_put_contents($dir.'/publishing.jsonl', json_encode($entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND | LOCK_EX);
    }
}
