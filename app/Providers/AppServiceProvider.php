<?php

namespace App\Providers;

use App\Services\Cloudflare\LocalPagesDeploymentService;
use App\Services\Cloudflare\LocalR2StorageService;
use App\Services\Cloudflare\PagesDeploymentService;
use App\Services\Cloudflare\R2StorageService;
use App\Services\CMS\JsonContentService;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Resolve at use time so tests can switch config after the app boots.
        $this->app->singleton(R2StorageService::class, function ($app) {
            if (config('cms.local_mode')) {
                config(['cloudflare.r2.public_url' => '']);

                return $app->make(LocalR2StorageService::class);
            }

            return $app->build(R2StorageService::class);
        });
        $this->app->singleton(PagesDeploymentService::class, function ($app) {
            return config('cms.local_mode')
                ? $app->make(LocalPagesDeploymentService::class)
                : $app->build(PagesDeploymentService::class);
        });

        if (config('cms.local_mode')) {
            config(['cloudflare.r2.public_url' => '']);
        }
    }

    public function boot(): void
    {
        // R2 object keys become public URLs only here, never in stored JSON config.
        Blade::directive('r2', function (string $expression): string {
            return "<?php echo \\App\\Services\\Cloudflare\\R2StorageService::url({$expression}); ?>";
        });

        Blade::directive('clean', function (string $expression): string {
            return "<?php echo app(\\App\\Support\\Sanitizer::class)->clean({$expression}); ?>";
        });
    }
}
