<?php

use App\Console\Commands\CleanupImagesCommand;
use App\Console\Commands\ExportWebsiteCommand;
use App\Console\Commands\GenerateWebsiteCommand;
use App\Console\Commands\PublishWebsiteCommand;
use App\Console\Commands\RollbackWebsiteCommand;
use App\Console\Commands\SyncWebsiteCommand;
use App\Console\Commands\TemplateCommand;
use App\Console\Commands\ValidateWebsiteCommand;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        health: '/up',
    )
    ->withCommands([
        ValidateWebsiteCommand::class,
        SyncWebsiteCommand::class,
        GenerateWebsiteCommand::class,
        PublishWebsiteCommand::class,
        RollbackWebsiteCommand::class,
        TemplateCommand::class,
        CleanupImagesCommand::class,
        ExportWebsiteCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->validateCsrfTokens(except: [
            'webhook/build',
        ]);
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'not.local' => \App\Http\Middleware\EnsureNotLocalMode::class,
        ]);
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Error pages are rendered by Laravel; publishing errors are also logged to
        // storage/app/publishing-logs for the dashboard.
    })
    ->create();
