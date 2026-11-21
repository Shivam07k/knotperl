<?php

namespace App\Services\Cloudflare;

class LocalPagesDeploymentService extends PagesDeploymentService
{
    public function deploy(string $zipPath): array
    {
        return ['mode' => 'local', 'status' => 'skipped'];
    }

    public function deployment(string $id): ?array
    {
        return null;
    }
}
