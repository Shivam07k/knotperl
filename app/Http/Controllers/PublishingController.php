<?php

namespace App\Http\Controllers;

use App\Services\CMS\PublishingService;
use App\Services\Cloudflare\PagesDeploymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PublishingController extends Controller
{
    public function __construct(
        private PublishingService $publishing,
        private PagesDeploymentService $deployment,
    ) {}

    public function publish(Request $request): RedirectResponse
    {
        $data = $request->validate(['local_only' => ['sometimes', 'boolean']]);
        try {
            $result = $this->publishing->publish(! ($data['local_only'] ?? false));
        } catch (RuntimeException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->route('dashboard')->with('success', 'Published '.$result['id'].' ('.$result['deployment']['mode'].').');
    }

    public function preview(): RedirectResponse
    {
        try {
            $result = $this->publishing->preview();
        } catch (RuntimeException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->route('dashboard')->with('preview', [
            'id' => $result['id'],
            'files' => $result['files'],
            'warnings' => count($result['validation']['warnings']),
        ]);
    }

    public function download(string $id): BinaryFileResponse
    {
        if (! preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            abort(404);
        }
        $path = config('publishing.paths.drafts').'/'.$id.'.zip';
        if (! is_file($path)) {
            abort(404);
        }

        return response()->download($path, $id.'.zip');
    }

    public function rollback(Request $request): RedirectResponse
    {
        $data = $request->validate(['id' => ['nullable', 'string', 'max:120']]);
        try {
            $result = $this->publishing->rollback($data['id'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }

        return redirect()->route('dashboard')->with('success', 'Rolled back to '.$result['id'].'.');
    }

    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        if (! $this->deployment->verifyWebhook($payload, $request->header('X-CMS-Signature'))) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        if (config('publishing.mode') === 'approval') {
            $path = storage_path('app/private/pending-approval.json');
            file_put_contents($path, json_encode(['queued_at' => now()->toIso8601String()]), LOCK_EX);

            return response()->json(['status' => 'pending-approval'], 202);
        }

        try {
            $result = $this->publishing->publish();
        } catch (RuntimeException $exception) {
            return response()->json(['error' => $exception->getMessage()], 500);
        }

        return response()->json(['status' => 'published', 'id' => $result['id']]);
    }
}
