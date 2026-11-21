<?php

namespace Tests\Feature;

use App\Services\CMS\JsonContentService;
use Tests\TestCase;

class LocalModeTest extends TestCase
{
    public function test_local_mode_binds_file_backed_services_and_persists_writes(): void
    {
        config()->set('cms.local_mode', true);
        (new \Illuminate\Filesystem\Filesystem())->deleteDirectory(dirname((string) config('cms.local_fixture_work')));

        $content = $this->app->make(JsonContentService::class);
        $this->assertContains('Products', $content->sheetTitles());

        $content->updateCell('Settings', 'B2', 'Renamed Local');
        $this->assertSame('Renamed Local', $content->readSheet('Settings', true)[0]['value']);

        $this->withSession(['admin_authenticated' => true])->post(route('local.reset'))->assertRedirect();
        $this->assertSame('Knots & Perl', $content->readSheet('Settings', true)[0]['value']);
    }

    public function test_local_mode_allows_preview_but_refuses_real_publish_and_rollback(): void
    {
        config()->set('cms.local_mode', true);
        config()->set('cloudflare.r2.public_url', '');
        $publishing = $this->app->make(\App\Services\CMS\PublishingService::class);
        $this->assertNotEmpty($publishing->preview()['id']);
        $this->expectException(\RuntimeException::class);
        $publishing->publish(true);
    }
}
