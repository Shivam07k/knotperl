<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }

    protected function sheetData(): array
    {
        $path = __DIR__.'/Fixtures/sheet-data.json';

        return json_decode((string) file_get_contents($path), true);
    }

    protected function tempPath(string $name): string
    {
        $path = sys_get_temp_dir().'/kp-tests/'.bin2hex(random_bytes(4)).'/'.$name;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }

        return $path;
    }

    protected function removeTree(string $path): void
    {
        $root = is_dir($path) ? $path : dirname($path);
        if (is_dir($root)) {
            (new \Illuminate\Filesystem\Filesystem())->deleteDirectory($root);
        }
    }
}
