<?php

namespace App\Services\Cloudflare;

use GdImage;
use RuntimeException;

class ImageManagerService
{
    public function __construct(private R2StorageService $r2) {}

    /**
     * Validate, deduplicate, optimize and upload one image. Returns the main record.
     * The video/texture case is handled by plain R2 uploads elsewhere; this is images.
     */
    public function upload(string $temporaryPath, string $folder, ?string $alt = null): array
    {
        $config = config('cloudflare.images');
        if (! is_file($temporaryPath)) {
            throw new RuntimeException('Uploaded file is missing.');
        }
        if (filesize($temporaryPath) > (int) $config['max_bytes']) {
            throw new RuntimeException('Image is larger than the allowed '.((int) $config['max_bytes'] / 1048576).' MB.');
        }

        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
        if (! in_array($mime, $config['allowed_mime'], true)) {
            throw new RuntimeException('Unsupported image type: '.$mime);
        }
        $size = @getimagesize($temporaryPath);
        if ($size === false) {
            throw new RuntimeException('File is not a readable image.');
        }

        $hash = hash_file('sha256', $temporaryPath);
        $hashKey = $this->normalizeFolder($folder).'.hashes/'.$hash.'.json';
        $existing = $this->r2->getJson($hashKey);
        if (is_array($existing) && ! empty($existing['key']) && $this->r2->exists($existing['key'])) {
            return $existing;
        }

        $source = $this->createImage($temporaryPath, $mime);
        $extension = function_exists('imagewebp') ? 'webp' : 'jpg';
        $base = $this->normalizeFolder($folder).date('Ymd-His').'-'.substr($hash, 0, 10);
        $variants = [];

        foreach ((array) $config['widths'] as $width) {
            $resized = $this->resize($source, (int) $width);
            $key = $base.'-'.(int) $width.'.'.$extension;
            $this->r2->upload($key, $this->encode($resized, $extension), $extension === 'webp' ? 'image/webp' : 'image/jpeg');
            $variants[(int) $width] = R2StorageService::url($key);
            imagedestroy($resized);
        }

        $thumb = $this->resize($source, (int) $config['thumbnail']);
        $thumbKey = $base.'-thumb.'.$extension;
        $this->r2->upload($thumbKey, $this->encode($thumb, $extension), $extension === 'webp' ? 'image/webp' : 'image/jpeg');
        imagedestroy($thumb);
        imagedestroy($source);

        $record = [
            'key' => $base.'-'.max((array) $config['widths']).'.'.$extension,
            'url' => R2StorageService::url($base.'-'.max((array) $config['widths']).'.'.$extension),
            'thumbnail' => R2StorageService::url($thumbKey),
            'variants' => $variants,
            'hash' => $hash,
            'alt' => $alt ?? '',
            'mime' => $extension === 'webp' ? 'image/webp' : 'image/jpeg',
            'width' => $size[0],
            'height' => $size[1],
            'bytes' => filesize($temporaryPath),
            'uploaded_at' => now()->toIso8601String(),
        ];
        $this->r2->putJson($base.'.json', $record);
        $this->r2->putJson($hashKey, $record);

        return $record;
    }

    /** Uploads the replacement first; deletion of the old key is deferred. */
    public function replace(string $temporaryPath, string $folder, string $oldKey, ?string $alt = null): array
    {
        $new = $this->upload($temporaryPath, $folder, $alt);
        if ($oldKey !== '' && $oldKey !== $new['key']) {
            $this->queueDeletion($oldKey);
        }

        return $new;
    }

    public function queueDeletion(string $key): void
    {
        $path = (string) config('cloudflare.images.deletion_queue');
        $queue = is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
        $queue[] = [
            'key' => $key,
            'delete_after' => now()->addDays((int) config('cloudflare.images.retention_days'))->toIso8601String(),
        ];
        file_put_contents($path, json_encode($queue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    public function processDeletions(): array
    {
        $path = (string) config('cloudflare.images.deletion_queue');
        if (! is_file($path)) {
            return ['deleted' => [], 'waiting' => 0];
        }
        $queue = json_decode((string) file_get_contents($path), true) ?: [];
        $deleted = [];
        $remaining = [];
        foreach ($queue as $entry) {
            if (empty($entry['key']) || empty($entry['delete_after'])) {
                continue;
            }
            if (now()->toIso8601String() >= $entry['delete_after']) {
                $this->delete($entry['key']);
                $deleted[] = $entry['key'];
            } else {
                $remaining[] = $entry;
            }
        }
        file_put_contents($path, json_encode($remaining, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

        return ['deleted' => $deleted, 'waiting' => count($remaining)];
    }

    public function delete(string $key): void
    {
        // Only the exact object and its sidecars; never a prefix-wide delete.
        $this->r2->delete($key);
        $metaKey = dirname($key).'/'.pathinfo($key, PATHINFO_FILENAME).'.json';
        if ($metaKey !== $key) {
            $this->r2->delete($metaKey);
        }
    }

    public function search(string $prefix = '', string $query = ''): array
    {
        $objects = $this->r2->list($prefix);
        $results = [];
        foreach ($objects as $object) {
            $key = $object['key'];
            if (str_contains($key, '/.hashes/') || str_ends_with($key, '.json')) {
                continue;
            }
            if ($query !== '' && ! str_contains(strtolower(basename($key)), strtolower($query))) {
                continue;
            }
            $object['url'] = R2StorageService::url($key);
            $results[] = $object;
        }

        return $results;
    }

    public function metadata(string $key): ?array
    {
        return $this->r2->metadata($key);
    }

    private function normalizeFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $folder = preg_replace('#[^A-Za-z0-9/_-]#', '-', $folder) ?: '';

        return $folder === '' ? '' : $folder.'/';
    }

    private function createImage(string $path, string $mime): GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            default => false,
        };
        if (! $image instanceof GdImage) {
            throw new RuntimeException('Could not decode the image.');
        }

        return $image;
    }

    private function resize(GdImage $source, int $width): GdImage
    {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $newWidth = $sourceWidth <= $width ? $sourceWidth : $width;
        $newHeight = $sourceWidth <= $width ? $sourceHeight : max(1, (int) round($sourceHeight * $width / $sourceWidth));
        $target = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $newWidth, $newHeight, $transparent);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $newWidth, $newHeight, $sourceWidth, $sourceHeight);

        return $target;
    }

    private function encode(GdImage $image, string $extension): string
    {
        ob_start();
        if ($extension === 'webp' && function_exists('imagewebp')) {
            imagewebp($image, null, (int) config('cloudflare.images.quality'));
        } else {
            imagejpeg($image, null, (int) config('cloudflare.images.quality'));
        }

        return (string) ob_get_clean();
    }
}
