<?php

namespace Tests\Unit;

use App\Services\CMS\ContentBuilderService;
use App\Services\CMS\DataValidationService;
use App\Services\CMS\SpreadsheetSchemaService;
use Tests\TestCase;

class ContentBuilderServiceTest extends TestCase
{
    public function test_it_builds_relationships_settings_and_nested_menus(): void
    {
        config()->set('cloudflare.r2.public_url', 'https://images.knotsandperl.com');
        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($this->sheetData());
        $site = (new ContentBuilderService($schema))->build($validated['data']);

        $this->assertSame('Knots & Perl', $site['settings']['site_name']);
        $this->assertTrue($site['settings']['enable_intro']);
        $this->assertSame(3, $site['settings']['countdown_seconds']);
        $this->assertCount(2, $site['products']);
        $this->assertSame('Pearls', $site['products'][0]['category']['category_name']);
        $this->assertSame('Knots', $site['categories'][0]['children'][0]['category_name']);
        $this->assertSame('Pearls', $site['navigation']['header'][1]['children'][0]['label']);
        $this->assertSame('images.knotsandperl.com', parse_url($site['products'][0]['image_url'], PHP_URL_HOST));
    }

    public function test_seo_for_falls_back_to_page_metadata(): void
    {
        $schema = new SpreadsheetSchemaService();
        $validated = (new DataValidationService($schema))->validate($this->sheetData());
        $builder = new ContentBuilderService($schema);
        $site = $builder->build($validated['data']);

        $about = $builder->seoFor($site, 'about-us', $site['pages']['about-us']);
        $this->assertSame('About Knots & Perl', $about['title']);
        $this->assertStringContainsString('index, follow', $about['robots']);
    }
}
