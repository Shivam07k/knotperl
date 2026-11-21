<?php

namespace Tests\Feature;

use Tests\TestCase;

class ImportSheetContentCommandTest extends TestCase
{
    public function test_import_writes_all_twelve_tabs_in_the_expected_shape(): void
    {
        config()->set('cms.local_mode', true);
        $out = $this->tempPath('imported.json');

        $this->artisan('cms:content:import', ['--out' => $out])->assertSuccessful();

        $data = json_decode((string) file_get_contents($out), true);
        $expected = array_keys(json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true));
        $this->assertSame($expected, array_keys($data));
        $this->assertSame('Knots & Perl', $data['Settings'][0]['value']);
    }
}
