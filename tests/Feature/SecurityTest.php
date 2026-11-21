<?php

namespace Tests\Feature;

use App\Services\Cloudflare\PagesDeploymentService;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    public function test_webhook_requires_a_valid_signature(): void
    {
        config()->set('publishing.webhook_secret', 'top-secret');
        $service = $this->app->make(PagesDeploymentService::class);
        $payload = '{"changed":true}';

        $this->assertTrue($service->verifyWebhook($payload, 'sha256='.hash_hmac('sha256', $payload, 'top-secret')));
        $this->assertFalse($service->verifyWebhook($payload, 'sha256=deadbeef'));
        $this->assertFalse($service->verifyWebhook($payload, null));
    }

    public function test_generated_output_never_contains_credentials(): void
    {
        config()->set('cloudflare.r2.secret_access_key', 'ALSO-HIDDEN');
        config()->set('publishing.webhook_secret', 'HOOK-SECRET');
        $secret = 'SHOULD-NOT-APPEAR';

        $files = array_merge(
            glob(public_path('js/*.js')) ?: [],
            glob(public_path('css/*.css')) ?: []
        );
        foreach ($files as $file) {
            $this->assertStringNotContainsString($secret, (string) file_get_contents($file));
        }
        $this->assertStringNotContainsString($secret, (string) file_get_contents(base_path('.env.example')));
        $this->assertStringNotContainsString('ALSO-HIDDEN', (string) file_get_contents(base_path('.env.example')));
    }
}
