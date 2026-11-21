<?php

namespace Tests\Feature;

use App\Services\CMS\JsonContentService;
use App\Services\CMS\PublishingService;
use App\Services\CMS\SpreadsheetSchemaService;
use App\Services\Cloudflare\PagesDeploymentService;

use Tests\TestCase;

class PublishingServiceTest extends TestCase
{
    public function test_a_successful_publish_swaps_the_live_directory(): void
    {
        $root = $this->configurePaths();
        $this->bindServices();

        $result = $this->app->make(PublishingService::class)->publish(true);

        $this->assertStringStartsWith('live-', $result['id']);
        $this->assertFileExists(config('publishing.paths.current').'/index.html');
        $this->assertFileExists(config('publishing.paths.generated').'/manifest.json');
        $this->assertStringContainsString('success', (string) file_get_contents(config('publishing.paths.logs').'/publishing.jsonl'));
        $this->removeTree($root);
    }

    public function test_failed_deployment_keeps_the_previous_live_site(): void
    {
        $root = $this->configurePaths();
        $sheets = \Mockery::mock(JsonContentService::class);
        $sheets->shouldReceive('sheetTitles')->andReturn((new SpreadsheetSchemaService())->tabNames());
        $sheets->shouldReceive('headers')->andReturnUsing(fn ($tab) => (new SpreadsheetSchemaService())->expectedHeaders($tab));
        $sheets->shouldReceive('readAll')->andReturn($this->sheetData());
        $this->instance(JsonContentService::class, $sheets);

        $deployment = \Mockery::mock(PagesDeploymentService::class);
        $deployment->shouldReceive('deploy')->andThrow(new \RuntimeException('Pages is down'));
        $this->instance(PagesDeploymentService::class, $deployment);

        try {
            $this->app->make(PublishingService::class)->publish(true);
            $this->fail('Expected the publish to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('live site was not changed', $exception->getMessage());
        }

        $this->assertFileDoesNotExist(config('publishing.paths.current').'/index.html');
        $this->assertStringContainsString('failed', (string) file_get_contents(config('publishing.paths.logs').'/publishing.jsonl'));
        $this->removeTree($root);
    }

    public function test_rollback_restores_a_previous_snapshot(): void
    {
        $root = $this->configurePaths();
        $this->bindServices();
        $service = $this->app->make(PublishingService::class);
        $service->publish(true);
        $service->publish(true);

        $this->assertNotEmpty(glob(config('publishing.paths.backups').'/*'));
        $result = $service->rollback();
        $this->assertStringStartsWith('rollback-', $result['id']);
        $this->assertFileExists(config('publishing.paths.current').'/index.html');
        $this->removeTree($root);
    }

    private function configurePaths(): string
    {
        $root = $this->tempPath('publishing');
        $root = dirname($root);
        config()->set('publishing.paths.generated', $root.'/generated');
        config()->set('publishing.paths.current', $root.'/generated/current');
        config()->set('publishing.paths.drafts', $root.'/generated/drafts');
        config()->set('publishing.paths.artifact', $root.'/generated/artifact.zip');
        config()->set('publishing.paths.logs', $root.'/logs');
        config()->set('publishing.paths.backups', $root.'/backups');
        config()->set('publishing.keep_backups', 5);
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');

        return $root;
    }

    private function bindServices(): void
    {
        $schema = new SpreadsheetSchemaService();
        $sheets = \Mockery::mock(JsonContentService::class);
        $sheets->shouldReceive('sheetTitles')->andReturn($schema->tabNames());
        $sheets->shouldReceive('headers')->andReturnUsing(fn ($tab) => $schema->expectedHeaders($tab));
        $sheets->shouldReceive('readAll')->andReturn($this->sheetData());
        $this->instance(JsonContentService::class, $sheets);

        $deployment = \Mockery::mock(PagesDeploymentService::class);
        $deployment->shouldReceive('deploy')->andReturn(['mode' => 'cloudflare', 'status' => 'success', 'id' => 'dep-1']);
        $this->instance(PagesDeploymentService::class, $deployment);
    }
}
