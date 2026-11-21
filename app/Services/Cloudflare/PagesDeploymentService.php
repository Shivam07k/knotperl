<?php

namespace App\Services\Cloudflare;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PagesDeploymentService
{
    public function deploy(string $zipPath): array
    {
        if (! is_file($zipPath)) {
            throw new RuntimeException('Deployment artifact is missing.');
        }
        if (config('cloudflare.pages.api_token') && config('cloudflare.pages.project')) {
            return $this->deployToPages($zipPath);
        }
        if (config('publishing.github.token') && config('publishing.github.repository')) {
            // GitHub is used as the build trigger; the committed artifact is deployed
            // by .github/workflows/deploy.yml.
            return $this->dispatchGithub();
        }

        throw new RuntimeException('No deployment target configured. Set Cloudflare Pages or GitHub credentials.');
    }

    public function deployToPages(string $zipPath): array
    {
        $account = (string) config('cloudflare.pages.account_id');
        $project = (string) config('cloudflare.pages.project');
        $handle = fopen($zipPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Could not read the deployment artifact.');
        }
        try {
            $response = Http::withToken((string) config('cloudflare.pages.api_token'))
                ->attach('file', $handle, basename($zipPath))
                ->post("https://api.cloudflare.com/client/v4/accounts/{$account}/pages/projects/{$project}/deployments", [
                    'branch' => (string) config('publishing.github.branch'),
                    'commit_message' => 'Publish from website CMS '.now()->toIso8601String(),
                ]);
        } finally {
            fclose($handle);
        }

        if ($response->failed()) {
            throw new RuntimeException('Cloudflare Pages deployment failed: '.$response->body());
        }
        $json = $response->json();

        return [
            'mode' => 'cloudflare',
            'id' => $json['result']['id'] ?? null,
            'url' => $json['result']['url'] ?? null,
            'status' => $json['result']['latest_stage']['status'] ?? 'queued',
        ];
    }

    public function dispatchGithub(): array
    {
        $repo = (string) config('publishing.github.repository');
        $workflow = (string) config('publishing.github.workflow');
        $response = Http::withToken((string) config('publishing.github.token'))
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->post("https://api.github.com/repos/{$repo}/actions/workflows/{$workflow}/dispatches", [
                'ref' => (string) config('publishing.github.branch'),
            ]);
        if ($response->failed() && $response->status() !== 204) {
            throw new RuntimeException('GitHub workflow dispatch failed: '.$response->body());
        }

        return ['mode' => 'github', 'status' => 'queued', 'repository' => $repo];
    }

    public function deployment(string $id): ?array
    {
        $account = (string) config('cloudflare.pages.account_id');
        $project = (string) config('cloudflare.pages.project');
        $response = Http::withToken((string) config('cloudflare.pages.api_token'))
            ->get("https://api.cloudflare.com/client/v4/accounts/{$account}/pages/projects/{$project}/deployments/{$id}");

        return $response->successful() ? ($response->json('result') ?? null) : null;
    }

    public function verifyWebhook(string $payload, ?string $header): bool
    {
        $secret = (string) config('publishing.webhook_secret');
        if ($secret === '' || $header === null) {
            return false;
        }
        $expected = 'sha256='.hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $header);
    }
}
