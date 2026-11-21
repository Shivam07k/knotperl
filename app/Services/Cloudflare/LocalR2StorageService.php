<?php

namespace App\Services\Cloudflare;

use RuntimeException;

class LocalR2StorageService extends R2StorageService
{
    public function upload(string $key, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $path = $this->path($key);
        $directory = dirname($path);
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($path, $contents, LOCK_EX);

        return [
            'key' => ltrim($key, '/'),
            'url' => R2StorageService::url($key),
            'etag' => md5($contents),
        ];
    }

    public function putJson(string $key, array $data): array
    {
        return $this->upload($key, (string) json_encode($data, JSON_UNESCAPED_SLASHES), 'application/json');
    }

    public function getJson(string $key): ?array
    {
        $path = $this->path($key);
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function exists(string $key): bool
    {
        return is_file($this->path($key));
    }

    public function delete(string $key): void
    {
        $path = $this->path($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function list(string $prefix = '', int $max = 1000): array
    {
        $root = $this->root();
        if (! is_dir($root)) {
            return [];
        }
        $prefix = ltrim($prefix, '/');
        $objects = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $key = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                continue;
            }
            $objects[] = [
                'key' => $key,
                'size' => $file->getSize(),
                'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                'etag' => (string) md5_file($file->getPathname()),
            ];
        }
        usort($objects, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        return array_slice($objects, 0, min(max($max, 1), 1000));
    }

    public function metadata(string $key): ?array
    {
        $path = $this->path($key);
        if (! is_file($path)) {
            return null;
        }

        return [
            'size' => (int) filesize($path),
            'type' => (string) (mime_content_type($path) ?: 'application/octet-stream'),
            'modified' => date('Y-m-d H:i:s', filemtime($path)),
        ];
    }

    public function test(): bool
    {
        return true;
    }

    private function root(): string
    {
        return rtrim((string) config('cms.local_r2_path'), '/\\');
    }

    private function path(string $key): string
    {
        $key = ltrim($key, '/');
        if ($key === '' || preg_match('#(^|/)\.\.(/|$)#', $key)) {
            throw new RuntimeException('Invalid local R2 object key.');
        }

        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $key);
    }
}
