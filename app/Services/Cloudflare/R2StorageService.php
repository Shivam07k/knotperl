<?php

namespace App\Services\Cloudflare;

use Aws\S3\S3Client;
use RuntimeException;

class R2StorageService
{
    private ?S3Client $client = null;

    public static function url(mixed $reference): string
    {
        $value = trim((string) $reference);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return rtrim((string) config('cloudflare.r2.public_url'), '/').'/'.ltrim($value, '/');
    }

    public function upload(string $key, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $result = $this->client()->putObject([
            'Bucket' => $this->bucket(),
            'Key' => ltrim($key, '/'),
            'Body' => $contents,
            'ContentType' => $contentType,
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return [
            'key' => ltrim($key, '/'),
            'url' => self::url($key),
            'etag' => trim((string) ($result['ETag'] ?? ''), '"'),
        ];
    }

    public function putJson(string $key, array $data): array
    {
        return $this->upload($key, json_encode($data, JSON_UNESCAPED_SLASHES), 'application/json');
    }

    public function getJson(string $key): ?array
    {
        try {
            $result = $this->client()->getObject(['Bucket' => $this->bucket(), 'Key' => ltrim($key, '/')]);
        } catch (\Throwable) {
            return null;
        }
        $decoded = json_decode((string) $result['Body'], true);

        return is_array($decoded) ? $decoded : null;
    }

    public function exists(string $key): bool
    {
        return $this->client()->doesObjectExist($this->bucket(), ltrim($key, '/'));
    }

    public function delete(string $key): void
    {
        $this->client()->deleteObject(['Bucket' => $this->bucket(), 'Key' => ltrim($key, '/')]);
    }

    public function list(string $prefix = '', int $max = 1000): array
    {
        $result = $this->client()->listObjectsV2([
            'Bucket' => $this->bucket(),
            'Prefix' => ltrim($prefix, '/'),
            'MaxKeys' => min(max($max, 1), 1000),
        ]);
        $objects = [];
        foreach ($result['Contents'] ?? [] as $object) {
            $objects[] = [
                'key' => (string) $object['Key'],
                'size' => (int) $object['Size'],
                'modified' => (string) ($object['LastModified'] ?? ''),
                'etag' => trim((string) ($object['ETag'] ?? ''), '"'),
            ];
        }

        return $objects;
    }

    public function metadata(string $key): ?array
    {
        try {
            $result = $this->client()->headObject(['Bucket' => $this->bucket(), 'Key' => ltrim($key, '/')]);
        } catch (\Throwable) {
            return null;
        }

        return [
            'size' => (int) ($result['ContentLength'] ?? 0),
            'type' => (string) ($result['ContentType'] ?? ''),
            'modified' => (string) ($result['LastModified'] ?? ''),
        ];
    }

    public function test(): bool
    {
        $this->client()->headBucket(['Bucket' => $this->bucket()]);

        return true;
    }

    private function bucket(): string
    {
        $bucket = (string) config('cloudflare.r2.bucket');
        if ($bucket === '') {
            throw new RuntimeException('CLOUDFLARE_R2_BUCKET is not configured.');
        }

        return $bucket;
    }

    private function client(): S3Client
    {
        if ($this->client !== null) {
            return $this->client;
        }
        $endpoint = (string) config('cloudflare.r2.endpoint');
        $key = (string) config('cloudflare.r2.access_key_id');
        $secret = (string) config('cloudflare.r2.secret_access_key');
        if ($endpoint === '' || $key === '' || $secret === '') {
            throw new RuntimeException('Cloudflare R2 credentials are not configured.');
        }

        return $this->client = new S3Client([
            'version' => 'latest',
            'region' => (string) config('cloudflare.r2.region', 'auto'),
            'endpoint' => $endpoint,
            'credentials' => ['key' => $key, 'secret' => $secret],
            'signature_version' => 'v4',
            'retries' => 2,
        ]);
    }
}
