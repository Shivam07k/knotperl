<?php

namespace Tests\Unit;

use App\Services\CMS\DataValidationService;
use App\Services\CMS\SheetWriteService;
use App\Services\CMS\JsonContentService;
use App\Services\CMS\SpreadsheetSchemaService;
use Mockery;
use Tests\TestCase;

class SheetWriteServiceTest extends TestCase
{
    private array $data;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->data = $this->sheetData();
        $this->logPath = $this->tempPath('logs/admin-actions.log');
        config()->set('publishing.paths.logs', dirname($this->logPath));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->logPath);
        parent::tearDown();
    }

    public function test_create_assigns_id_and_timestamp_and_writes_one_row(): void
    {
        $headers = $this->schema()->expectedHeaders('Products');
        $sheets = $this->sheets();
        $sheets->shouldReceive('appendRow')->once()
            ->with('Products', Mockery::on(function (array $values) use ($headers): bool {
                $this->assertSame('pr004', $values[array_search('product_id', $headers, true)]);
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}/',
                    (string) $values[array_search('updated_at', $headers, true)]
                );
                $this->assertMatchesRegularExpression(
                    '/^\d{4}-\d{2}-\d{2}/',
                    (string) $values[array_search('created_at', $headers, true)]
                );

                return true;
            }))
            ->andReturn(5);
        $sheets->shouldNotReceive('updateRow');

        $result = $this->service($sheets)->save('Products', [
            'product_name' => 'New Piece',
            'slug' => 'new-piece',
            'category_id' => 'c001',
            'regular_price' => '99',
            'primary_image' => 'assets/products/pr004/main.jpg',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(5, $result['row']);
        $this->assertSame([], $result['errors']);

        $log = $this->logLines();
        $this->assertCount(1, $log);
        $this->assertSame('create', $log[0]['action']);
        $this->assertSame('Products', $log[0]['tab']);
        $this->assertSame('pr004', $log[0]['id']);
        $this->assertArrayNotHasKey('product_name', $log[0]);
    }

    public function test_invalid_submitted_row_is_rejected_without_writing(): void
    {
        $sheets = $this->sheets();
        $sheets->shouldNotReceive('appendRow');
        $sheets->shouldNotReceive('updateRow');

        $result = $this->service($sheets)->save('Products', [
            'product_name' => 'Bad Price',
            'slug' => 'bad-price',
            'category_id' => 'c001',
            'regular_price' => 'not-a-number',
            'primary_image' => 'assets/products/pr004/main.jpg',
        ]);

        $this->assertFalse($result['ok']);
        $messages = implode(' ', array_column($result['errors'], 'message'));
        $this->assertStringContainsString('non-negative number', $messages);
        $this->assertSame([], $this->logLines());
    }

    public function test_scoped_validation_ignores_other_tabs_but_full_validation_does_not(): void
    {
        $data = $this->sheetData();
        $data['Products'][1]['category_id'] = 'missing'; // Products row 3
        $data['Pages'][0]['title'] = ''; // Pages row 2 (another tab)
        $validator = new DataValidationService($this->schema());

        $full = $validator->validate($data);
        $this->assertFalse($full['ok']);
        $messages = implode(' ', array_column($full['errors'], 'message'));
        $this->assertStringContainsString('does not exist in Categories', $messages);
        $this->assertStringContainsString('Title is required', $messages);

        $scoped = $validator->validateScope($data, ['Products' => [2]]);
        $this->assertTrue($scoped['ok'], json_encode($scoped));
        $this->assertSame([], $scoped['errors']);
        $this->assertSame([], $scoped['warnings']);

        $scopedRow3 = $validator->validateScope($data, ['Products' => [3]]);
        $this->assertFalse($scopedRow3['ok']);
        $this->assertCount(1, $scopedRow3['errors']);
        $this->assertSame('Products', $scopedRow3['errors'][0]['sheet']);
        $this->assertSame(3, $scopedRow3['errors'][0]['row']);
    }

    public function test_stale_checksum_blocks_update(): void
    {
        $sheets = $this->sheets();
        $sheets->shouldNotReceive('updateRow');
        $sheets->shouldNotReceive('appendRow');
        $service = $this->service($sheets);

        $stale = $service->save('Products', [
            '__row' => 3,
            '__checksum' => str_repeat('0', 64),
            'product_name' => 'Changed',
        ]);

        $this->assertFalse($stale['ok']);
        $this->assertSame(3, $stale['row']);
        $this->assertStringContainsString('conflict', $stale['errors'][0]['message']);

        $missing = $service->save('Products', ['__row' => 3, 'product_name' => 'Changed']);
        $this->assertFalse($missing['ok']);
        $this->assertStringContainsString('conflict', $missing['errors'][0]['message']);
        $this->assertSame([], $this->logLines());
    }

    public function test_update_uses_expected_row_and_delete_removes_it(): void
    {
        $headers = $this->schema()->expectedHeaders('Products');
        $sheets = $this->sheets();
        $service = $this->service($sheets);
        $checksum = $service->checksum($this->data['Products'][0], $headers);

        $sheets->shouldReceive('updateRow')->once()
            ->with('Products', 2, Mockery::on(function (array $values) use ($headers): bool {
                $this->assertSame('pr001', $values[array_search('product_id', $headers, true)]);
                $this->assertSame('Updated Name', $values[array_search('product_name', $headers, true)]);
                $this->assertNotSame('2026-01-01', (string) $values[array_search('updated_at', $headers, true)]);

                return true;
            }))
            ->andReturnNull();

        $updated = $service->save('Products', [
            '__row' => 2,
            '__checksum' => $checksum,
            'product_name' => 'Updated Name',
        ]);

        $this->assertTrue($updated['ok'], json_encode($updated['errors']));
        $this->assertSame(2, $updated['row']);

        $deleteChecksum = $service->checksum($this->data['Products'][1], $headers);
        $sheets->shouldReceive('deleteRow')->once()->with('Products', 3)->andReturnNull();
        $deleted = $service->delete('Products', 3, $deleteChecksum);

        $this->assertTrue($deleted['ok'], json_encode($deleted['errors']));
        $this->assertSame(3, $deleted['row']);

        $log = $this->logLines();
        $this->assertCount(2, $log);
        $this->assertSame(['update', 'delete'], array_column($log, 'action'));
        $this->assertSame(['pr001', 'pr002'], array_column($log, 'id'));
    }

    public function test_delete_with_stale_checksum_does_not_write(): void
    {
        $sheets = $this->sheets();
        $sheets->shouldNotReceive('deleteRow');

        $result = $this->service($sheets)->delete('Products', 3, str_repeat('0', 64));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('conflict', $result['errors'][0]['message']);
        $this->assertSame([], $this->logLines());
    }

    public function test_editing_earlier_row_to_reuse_later_unique_value_is_rejected(): void
    {
        $headers = $this->schema()->expectedHeaders('Products');
        $sheets = $this->sheets();
        $sheets->shouldNotReceive('updateRow');
        $sheets->shouldNotReceive('appendRow');
        $service = $this->service($sheets);
        $checksum = $service->checksum($this->data['Products'][0], $headers);

        $result = $service->save('Products', [
            '__row' => 2,
            '__checksum' => $checksum,
            'slug' => $this->data['Products'][2]['slug'], // already on row 4
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(2, $result['row']);
        $this->assertStringContainsString('Duplicate slug', implode(' ', array_column($result['errors'], 'message')));
        $this->assertSame([], $this->logLines());
    }

    public function test_editing_later_row_to_reuse_earlier_unique_value_is_still_rejected(): void
    {
        $headers = $this->schema()->expectedHeaders('Products');
        $sheets = $this->sheets();
        $sheets->shouldNotReceive('updateRow');
        $sheets->shouldNotReceive('appendRow');
        $service = $this->service($sheets);
        $checksum = $service->checksum($this->data['Products'][2], $headers);

        $result = $service->save('Products', [
            '__row' => 4,
            '__checksum' => $checksum,
            'slug' => $this->data['Products'][0]['slug'], // already on row 2
        ]);

        $this->assertFalse($result['ok']);
        $this->assertSame(4, $result['row']);
        $this->assertStringContainsString('Duplicate slug', implode(' ', array_column($result['errors'], 'message')));
        $this->assertSame([], $this->logLines());
    }

    public function test_non_duplicate_edit_still_succeeds(): void
    {
        $sheets = $this->sheets();
        $sheets->shouldReceive('updateRow')->once()->with('Products', 2, Mockery::any())->andReturnNull();
        $service = $this->service($sheets);
        $headers = $this->schema()->expectedHeaders('Products');
        $checksum = $service->checksum($this->data['Products'][0], $headers);

        $result = $service->save('Products', [
            '__row' => 2,
            '__checksum' => $checksum,
            'product_name' => 'Renamed Pendant',
        ]);

        $this->assertTrue($result['ok'], json_encode($result['errors']));
        $this->assertSame(2, $result['row']);
        $this->assertCount(1, $this->logLines());
    }

    private function schema(): SpreadsheetSchemaService
    {
        return new SpreadsheetSchemaService();
    }

    private function sheets(): Mockery\MockInterface
    {
        $sheets = Mockery::mock(JsonContentService::class);
        $sheets->shouldReceive('readSheet')->andReturnUsing(
            fn (string $tab, bool $fresh = false): array => $this->data[$tab] ?? []
        );
        $sheets->shouldReceive('clearCache')->andReturnNull();

        return $sheets;
    }

    private function service(Mockery\MockInterface $sheets): SheetWriteService
    {
        $schema = $this->schema();

        return new SheetWriteService($sheets, new DataValidationService($schema), $schema);
    }

    private function logLines(): array
    {
        $contents = is_file($this->logPath) ? trim((string) file_get_contents($this->logPath)) : '';
        if ($contents === '') {
            return [];
        }

        return array_values(array_map(
            static fn (string $line): array => json_decode($line, true) ?? [],
            explode("\n", $contents)
        ));
    }
}
