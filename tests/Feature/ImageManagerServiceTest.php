<?php

namespace Tests\Feature;

use App\Services\Cloudflare\ImageManagerService;
use App\Services\Cloudflare\R2StorageService;
use Tests\TestCase;

class ImageManagerServiceTest extends TestCase
{
    public function test_duplicate_hash_returns_existing_object_without_reuploading(): void
    {
        $queue = $this->tempPath('deletions.json');
        config()->set('cloudflare.images.deletion_queue', $queue);
        $existing = ['key' => 'products/pr001/main.webp', 'url' => 'https://images.example.com/products/pr001/main.webp', 'hash' => 'abc'];

        $r2 = \Mockery::mock(R2StorageService::class);
        $r2->shouldReceive('getJson')->once()->andReturn($existing);
        $r2->shouldReceive('exists')->once()->with('products/pr001/main.webp')->andReturn(true);
        $r2->shouldNotReceive('upload');
        $this->instance(R2StorageService::class, $r2);

        $file = $this->tempPath('sample.jpg');
        $image = imagecreatetruecolor(12, 12);
        imagejpeg($image, $file);
        imagedestroy($image);

        $result = $this->app->make(ImageManagerService::class)->upload($file, 'products/pr001/');

        $this->assertSame($existing, $result);
        $this->removeTree($file);
    }

    public function test_it_rejects_non_image_files(): void
    {
        config()->set('cloudflare.images.deletion_queue', $this->tempPath('deletions.json'));
        $r2 = \Mockery::mock(R2StorageService::class);
        $this->instance(R2StorageService::class, $r2);

        $file = $this->tempPath('notes.txt');
        file_put_contents($file, 'not an image');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');
        $this->app->make(ImageManagerService::class)->upload($file, 'products/');
    }

    public function test_deletion_respects_retention_period(): void
    {
        config()->set('cloudflare.images.deletion_queue', $this->tempPath('deletions.json'));
        config()->set('cloudflare.images.retention_days', 0);
        $r2 = \Mockery::mock(R2StorageService::class);
        $r2->shouldReceive('delete')->once()->with('products/old.webp');
        $r2->shouldReceive('delete')->once()->with('products/old.json');
        $this->instance(R2StorageService::class, $r2);

        $service = $this->app->make(ImageManagerService::class);
        $service->queueDeletion('products/old.webp');
        $result = $service->processDeletions();

        $this->assertSame(['products/old.webp'], $result['deleted']);
        $this->assertSame(0, $result['waiting']);
    }
}
