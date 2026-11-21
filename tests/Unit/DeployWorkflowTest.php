<?php

namespace Tests\Unit;

use Tests\TestCase;

class DeployWorkflowTest extends TestCase
{
    public function test_deploy_workflow_uses_json_source_and_pages_deploy(): void
    {
        $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));

        $this->assertStringContainsString('workflow_dispatch', $yml);
        $this->assertStringContainsString('CMS_CONTENT_SOURCE: json', $yml);
        $this->assertStringContainsString('cms:publish', $yml);
        $this->assertStringContainsString('r2.cloudflarestorage.com', $yml);
        $this->assertStringContainsString('--region auto', $yml);
        $this->assertStringContainsString('cloudflare/wrangler-action@v4', $yml);
        $this->assertStringContainsString('pages deploy storage/app/generated/current', $yml);
        $this->assertStringContainsString('x-status-secret', $yml);
        $this->assertStringContainsString('if: failure()', $yml);
    }
}
