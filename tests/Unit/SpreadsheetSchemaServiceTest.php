<?php

namespace Tests\Unit;

use App\Services\CMS\SpreadsheetSchemaService;
use Tests\TestCase;

class SpreadsheetSchemaServiceTest extends TestCase
{
    public function test_it_knows_all_twelve_tabs(): void
    {
        $schema = new SpreadsheetSchemaService();

        $this->assertCount(12, $schema->tabNames());
        $this->assertContains('Products', $schema->tabNames());
        $this->assertContains('Redirects', $schema->tabNames());
        $this->assertContains('slug', $schema->expectedHeaders('Products'));
    }

    public function test_it_reports_missing_tabs_and_columns(): void
    {
        $schema = new SpreadsheetSchemaService();
        $tabs = $schema->tabNames();
        $headers = [];
        foreach ($tabs as $tab) {
            $headers[$tab] = $schema->expectedHeaders($tab);
        }
        unset($tabs[array_search('SEO', $tabs, true)]);
        unset($headers['Products'][array_search('SKU', $headers['Products'], true)]);

        $report = $schema->verify(array_values($tabs), $headers);

        $this->assertFalse($report['ok']);
        $this->assertContains('SEO', $report['missing_tabs']);
        $this->assertSame(['SKU'], $report['missing_columns']['Products']);
    }

    public function test_a_complete_spreadsheet_passes(): void
    {
        $schema = new SpreadsheetSchemaService();
        $tabs = $schema->tabNames();
        $headers = [];
        foreach ($tabs as $tab) {
            $headers[$tab] = $schema->expectedHeaders($tab);
        }

        $this->assertTrue($schema->verify($tabs, $headers)['ok']);
    }
}
