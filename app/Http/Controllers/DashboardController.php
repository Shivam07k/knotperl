<?php

namespace App\Http\Controllers;

use App\Services\CMS\PublishingService;
use App\Services\Cloudflare\R2StorageService;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

class DashboardController extends Controller
{
    public function __construct(
        private PublishingService $publishing,
        private R2StorageService $r2,
    ) {}

    public function index(): View
    {
        return view('dashboard.index', [
            'status' => $this->safe(fn () => $this->publishing->status()) ?? [],
            'r2Status' => $this->safe(fn () => $this->r2->test() ? 'connected' : 'unknown'),
            'siteUrl' => config('publishing.site_url'),
            'mode' => config('publishing.mode'),
        ]);
    }

    public function validation(): View|RedirectResponse
    {
        try {
            $report = $this->publishing->validate();
        } catch (RuntimeException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return view('dashboard.validation', ['report' => $report]);
    }

    public function resetFixture(): RedirectResponse
    {
        (new Filesystem())->delete((string) config('cms.local_fixture_work'));

        return redirect()->back()->with('success', 'Local fixture reset.');
    }

    private function safe(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return null;
        }
    }
}
