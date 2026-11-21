<?php

namespace Tests\Unit;

use App\Services\CMS\DataValidationService;
use App\Services\CMS\SpreadsheetSchemaService;
use Tests\TestCase;

class DataValidationServiceTest extends TestCase
{
    public function test_sample_data_is_valid(): void
    {
        $result = $this->validator()->validate($this->sheetData());

        $this->assertTrue($result['ok'], $this->validator()->formatReport($result));
        $this->assertCount(3, $result['data']['Products']);
        $this->assertTrue($result['data']['Products'][0]['featured']);
        $this->assertSame(480.0, $result['data']['Products'][0]['regular_price']);
    }

    public function test_it_flags_duplicate_slugs_and_missing_names(): void
    {
        $data = $this->sheetData();
        $data['Products'][1]['slug'] = $data['Products'][0]['slug'];
        $data['Products'][2]['product_name'] = '';

        $result = $this->validator()->validate($data);

        $this->assertFalse($result['ok']);
        $messages = implode(' ', array_column($result['errors'], 'message'));
        $this->assertStringContainsString('Duplicate slug', $messages);
        $this->assertStringContainsString('Product name is required', $messages);
    }

    public function test_it_flags_invalid_category_price_and_image(): void
    {
        $data = $this->sheetData();
        $data['Products'][0]['category_id'] = 'missing';
        $data['Products'][0]['sale_price'] = '9999';
        $data['Products'][0]['primary_image'] = 'javascript:alert(1)';

        $result = $this->validator()->validate($data);
        $messages = implode(' ', array_column($result['errors'], 'message'));

        $this->assertStringContainsString('does not exist in Categories', $messages);
        $this->assertStringContainsString('higher than the regular price', $messages);
        $this->assertStringContainsString('Image reference', $messages);
    }

    public function test_it_normalizes_booleans_and_numbers(): void
    {
        $data = $this->sheetData();
        $data['Redirects'][0]['redirect_type'] = '302';
        $data['Testimonials'][0]['rating'] = '5';

        $result = $this->validator()->validate($data);

        $this->assertTrue($result['ok']);
        $this->assertSame(302, (int) $result['data']['Redirects'][0]['redirect_type']);
        $this->assertSame(5, $result['data']['Testimonials'][0]['rating']);
    }

    private function validator(): DataValidationService
    {
        return new DataValidationService(new SpreadsheetSchemaService());
    }
}
