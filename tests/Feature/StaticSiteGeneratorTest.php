<?php

namespace Tests\Feature;

use App\Services\CMS\ContentBuilderService;
use App\Services\CMS\JsonContentService;
use App\Services\CMS\DataValidationService;
use App\Services\CMS\PublishingService;
use App\Services\CMS\SpreadsheetSchemaService;
use App\Services\CMS\StaticSiteGenerator;
use App\Services\Cloudflare\PagesDeploymentService;

use Tests\TestCase;

class StaticSiteGeneratorTest extends TestCase
{
    public function test_it_generates_pages_json_and_hosting_files(): void
    {
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        config()->set('publishing.admin_url', 'https://admin.example.test');
        $target = $this->tempPath('site');

        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($this->sheetData());
        $site = (new ContentBuilderService($schema))->build($validated['data']);
        $result = (new StaticSiteGenerator(new ContentBuilderService($schema)))->render($site, $target);

        $this->assertGreaterThan(10, $result['files']);
        $this->assertFileExists($target.'/index.html');
        $this->assertFileExists($target.'/about-us/index.html');
        $this->assertFileExists($target.'/products/index.html');
        $this->assertFileExists($target.'/products/pearl-knot-pendant/index.html');
        $this->assertFileExists($target.'/category/pearls/index.html');
        $this->assertFileExists($target.'/data/products.json');
        $this->assertFileExists($target.'/sitemap.xml');
        $this->assertFileExists($target.'/sitemap.html');
        $this->assertFileExists($target.'/robots.txt');
        $this->assertFileExists($target.'/_redirects');
        $this->assertFileExists($target.'/_headers');
        $this->assertFileExists($target.'/css/site.css');
        $this->assertStringNotContainsString('/* Knots & Perl', (string) file_get_contents($target.'/css/site.css'));

        $html = (string) file_get_contents($target.'/index.html');
        $this->assertStringContainsString('Knots &amp; Perl', $html);
        $this->assertStringContainsString('data-countdown="3"', $html);
        $this->assertStringContainsString('data-sound="false"', $html);
        $this->assertStringContainsString('class="brand-mark"', $html);
        $this->assertStringContainsString('class="product-card"', $html);
        $this->assertStringContainsString('class="icon-button admin-link"', $html);
        $this->assertStringContainsString('https://admin.example.test', $html);
        $this->assertStringContainsString('.admin-link', (string) file_get_contents($target.'/css/site.css'));
        $this->assertStringNotContainsString('class="admin-float"', $html);
        $this->assertStringNotContainsString('id="intro-code"', $html);
        $this->assertStringContainsString('images.knotsandperl.com', $html);
        $this->assertStringContainsString('/products/pearl-knot-pendant/', $html);
        $this->assertStringNotContainsString('<?php', $html);
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $match);
        $this->assertSame('https://schema.org', json_decode($match[1] ?? '', true)['@context'] ?? null);

        $sitemap = (string) file_get_contents($target.'/sitemap.xml');
        $this->assertStringContainsString('https://knotsandperl.pages.dev/products/pearl-knot-pendant/', $sitemap);

        $json = json_decode((string) file_get_contents($target.'/data/products.json'), true);
        $this->assertCount(2, $json);
        $this->assertArrayNotHasKey('GOOGLE_CLIENT_SECRET', $json[0]);

        $this->removeTree($target);
    }

    public function test_theme_css_and_endpoint_origins_are_generated(): void
    {
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        $target = $this->tempPath('site-endpoints');

        $data = $this->sheetData();
        foreach ($data['Settings'] as &$row) {
            if ($row['key'] === 'form_endpoint') {
                $row['value'] = 'https://forms.example.com/contact';
            }
            if ($row['key'] === 'newsletter_endpoint') {
                $row['value'] = 'https://forms.example.com/sub';
            }
        }
        unset($row);

        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($data);
        $this->assertTrue($validated['ok']);
        $site = (new ContentBuilderService($schema))->build($validated['data']);
        (new StaticSiteGenerator(new ContentBuilderService($schema)))->render($site, $target);

        $this->assertFileExists($target.'/css/theme.css');
        $css = (string) file_get_contents($target.'/css/theme.css');
        $this->assertStringContainsString('html[data-theme="obsidian"]', $css);
        $this->assertStringContainsString('html[data-theme="pearl"]', $css);
        $this->assertStringContainsString('--accent: #d4af37', $css);

        $html = (string) file_get_contents($target.'/index.html');
        $siteCss = strpos($html, 'href="/css/site.css"');
        $themeCss = strpos($html, 'href="/css/theme.css"');
        $this->assertNotFalse($siteCss);
        $this->assertNotFalse($themeCss);
        $this->assertGreaterThan($siteCss, $themeCss);

        $contact = (string) file_get_contents($target.'/contact-us/index.html');
        $this->assertStringContainsString('data-contact-endpoint="https://forms.example.com/contact"', $contact);
        $this->assertStringContainsString('data-newsletter-endpoint="https://forms.example.com/sub"', $contact);

        $headers = (string) file_get_contents($target.'/_headers');
        $this->assertSame(1, substr_count($headers, "connect-src 'self' https://forms.example.com"));
        preg_match('/connect-src[^\n]*/', $headers, $match);
        $this->assertStringNotContainsString('*', $match[0] ?? '');

        $json = (string) file_get_contents($target.'/data/site.json');
        $this->assertStringNotContainsString('forms.example.com', $json);
        $this->assertStringNotContainsString('form_endpoint', $json);

        $this->removeTree($target);
    }

    public function test_no_endpoints_keeps_placeholder_and_self_only_csp(): void
    {
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        $target = $this->tempPath('site-no-endpoints');

        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($this->sheetData());
        $this->assertTrue($validated['ok']);
        $site = (new ContentBuilderService($schema))->build($validated['data']);
        (new StaticSiteGenerator(new ContentBuilderService($schema)))->render($site, $target);

        $contact = (string) file_get_contents($target.'/contact-us/index.html');
        $this->assertStringContainsString('Connect a form provider to receive submissions.', $contact);
        $this->assertStringContainsString('data-contact-endpoint=""', $contact);

        $headers = (string) file_get_contents($target.'/_headers');
        $this->assertSame(1, substr_count($headers, 'connect-src'));
        $this->assertStringContainsString("connect-src 'self'\n\n", $headers);
        $this->assertStringNotContainsString("connect-src 'self' http", $headers);

        $this->removeTree($target);
    }

    public function test_full_dataset_validation_still_blocks_preview_despite_scoped_validation(): void
    {
        $data = $this->sheetData();
        foreach ($data['Settings'] as &$row) {
            if ($row['key'] === 'form_endpoint') {
                $row['value'] = 'http://forms.example.com/contact';
            }
        }
        unset($row);

        $schema = new SpreadsheetSchemaService();
        $validator = new DataValidationService($schema);
        $this->assertTrue($validator->validateScope($data, ['Settings' => [2]])['ok']);

        $root = dirname($this->tempPath('publishing'));
        config()->set('publishing.paths.generated', $root.'/generated');
        config()->set('publishing.paths.current', $root.'/generated/current');
        config()->set('publishing.paths.drafts', $root.'/generated/drafts');
        config()->set('publishing.paths.artifact', $root.'/generated/artifact.zip');
        config()->set('publishing.paths.logs', $root.'/logs');
        config()->set('publishing.paths.backups', $root.'/backups');
        config()->set('publishing.strict', true);
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');

        $sheets = \Mockery::mock(JsonContentService::class);
        $sheets->shouldReceive('sheetTitles')->andReturn($schema->tabNames());
        $sheets->shouldReceive('headers')->andReturnUsing(fn ($tab) => $schema->expectedHeaders($tab));
        $sheets->shouldReceive('readAll')->andReturn($data);
        $this->instance(JsonContentService::class, $sheets);
        $this->instance(PagesDeploymentService::class, \Mockery::mock(PagesDeploymentService::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must use HTTPS');
        $this->app->make(PublishingService::class)->preview();
    }

    public function test_generated_file_count_includes_theme_css(): void
    {
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');
        config()->set('publishing.site_url', 'https://knotsandperl.pages.dev');
        $target = $this->tempPath('site-count');

        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($this->sheetData());
        $site = (new ContentBuilderService($schema))->build($validated['data']);
        $result = (new StaticSiteGenerator(new ContentBuilderService($schema)))->render($site, $target);

        $this->assertSame(count($result['routes']) + 9, $result['files']);
        $this->assertFileExists($target.'/css/theme.css');

        $this->removeTree($target);
    }
}
