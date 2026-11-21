<?php

namespace Tests\Feature;

use App\Services\Cloudflare\LocalR2StorageService;
use App\Services\Cloudflare\R2StorageService;
use App\Services\CMS\JsonContentService;
use Tests\TestCase;

class JsonContentSourceTest extends TestCase
{
    public function test_json_content_source_binds_file_backed_content_but_keeps_real_cloudflare_services(): void
    {
        config()->set('cms.local_mode', false);
        config()->set('cms.local_fixture_source', $this->tempPath('json-content-source.json'));
        config()->set('cms.local_fixture_work', $this->tempPath('json-content-source-work.json'));
        file_put_contents((string) config('cms.local_fixture_source'), json_encode([
            'Settings' => [['__row' => 2, 'key' => 'site_name', 'value' => 'Json Brand']],
        ]));

        $content = $this->app->make(JsonContentService::class);

        $this->assertInstanceOf(JsonContentService::class, $content);
        $this->assertSame('Json Brand', $content->readSheet('Settings', true)[0]['value']);
        $this->assertNotInstanceOf(LocalR2StorageService::class, $this->app->make(R2StorageService::class));
    }

    public function test_json_file_round_trips_every_tab_identically(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true);
        $this->useJsonContent($fixture, 'json-round-trip.json');

        $content = $this->app->make(JsonContentService::class);
        foreach ($fixture as $tab => $rows) {
            $this->assertSame($rows, $content->readSheet($tab, true), $tab);
        }
    }

    public function test_invalid_content_json_blocks_publish_without_touching_live_build(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true);
        $fixture['Pages'][1]['slug'] = $fixture['Pages'][0]['slug'];
        $this->useJsonContent($fixture, 'json-duplicate-slug.json');
        $current = (string) config('publishing.paths.current');
        $before = $this->directoryFingerprint($current);

        try {
            $this->app->make(\App\Services\CMS\PublishingService::class)->preview();
            $this->fail('Expected invalid content to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('duplicate', strtolower($exception->getMessage()));
        }

        $this->assertSame($before, $this->directoryFingerprint($current));
    }

    public function test_broken_product_category_reference_blocks_publish(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true);
        $fixture['Products'][0]['category_id'] = 'missing';
        $this->useJsonContent($fixture, 'json-bad-reference.json');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('category');
        $this->app->make(\App\Services\CMS\PublishingService::class)->preview();
    }

    private function useJsonContent(array $data, string $name): void
    {
        config()->set('cms.local_mode', false);
        config()->set('cms.local_fixture_source', $this->tempPath($name));
        config()->set('cms.local_fixture_work', $this->tempPath('work-'.$name));
        file_put_contents((string) config('cms.local_fixture_source'), (string) json_encode($data));
    }

    private function directoryFingerprint(string $directory): string
    {
        if (! is_dir($directory)) {
            return 'missing';
        }
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $files[] = substr($file->getPathname(), strlen($directory)).':'.md5((string) file_get_contents($file->getPathname()));
        }
        sort($files);

        return implode('|', $files);
    }
}
