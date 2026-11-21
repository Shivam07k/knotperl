<?php

namespace Tests\Feature;

use App\Services\CMS\JsonContentService;
use App\Services\CMS\SpreadsheetSchemaService;
use Tests\TestCase;

class NoGoogleIntegrationTest extends TestCase
{
    public function test_no_google_code_routes_or_config_remain(): void
    {
        $this->assertFileDoesNotExist(config_path('google.php'));
        foreach (['GoogleAuthService', 'GoogleDriveService', 'GoogleSheetsService', 'LocalGoogleSheetsService'] as $class) {
            $this->assertFalse(class_exists('App\\Services\\Google\\'.$class), $class);
        }

        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes())
            ->map(fn ($route): string => (string) $route->getName())
            ->implode('|');
        $this->assertStringNotContainsString('google.', $routes);
        $this->assertStringNotContainsString('spreadsheets.', $routes);
    }

    public function test_json_content_service_works_without_google_config(): void
    {
        $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true);
        config()->set('cms.local_mode', false);
        config()->set('cms.local_fixture_source', $this->tempPath('neutral-source.json'));
        config()->set('cms.local_fixture_work', $this->tempPath('neutral-work.json'));
        file_put_contents((string) config('cms.local_fixture_source'), (string) json_encode($fixture));

        $content = $this->app->make(JsonContentService::class);
        $this->assertInstanceOf(JsonContentService::class, $content);
        $this->assertContains('Products', $content->sheetTitles());
        $this->assertSame((new SpreadsheetSchemaService())->tabNames(), $content->sheetTitles());

        $content->updateCell('Settings', 'B2', 'No Google Brand');
        $this->assertSame('No Google Brand', $content->readSheet('Settings', true)[0]['value']);
    }
}
