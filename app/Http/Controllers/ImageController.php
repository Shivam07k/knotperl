<?php

namespace App\Http\Controllers;

use App\Services\Cloudflare\ImageManagerService;
use App\Services\CMS\JsonContentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ImageController extends Controller
{
    public function __construct(
        private ImageManagerService $images,
        private JsonContentService $sheets,
    ) {}

    public function index(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'prefix' => ['sometimes', 'string', 'max:120'],
            'q' => ['sometimes', 'string', 'max:120'],
        ]);
        try {
            $objects = $this->images->search($data['prefix'] ?? '', $data['q'] ?? '');
        } catch (RuntimeException $exception) {
            return redirect()->route('dashboard')->with('error', $exception->getMessage());
        }
        $queuePath = (string) config('cloudflare.images.deletion_queue');
        $queue = is_file($queuePath) ? (json_decode((string) file_get_contents($queuePath), true) ?: []) : [];

        return view('dashboard.images', [
            'objects' => $objects,
            'queue' => $queue,
            'prefix' => $data['prefix'] ?? '',
            'query' => $data['q'] ?? '',
        ]);
    }

    public function keys(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['string', 'max:120']]);

        return response()->json([
            'keys' => array_column($this->images->search('', $data['q'] ?? ''), 'key'),
        ]);
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp,gif', 'max:8192'],
            'folder' => ['required', 'string', 'max:120'],
            'alt' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            $record = $this->images->upload($request->file('image')->getRealPath(), $data['folder'], $data['alt'] ?? null);
        } catch (RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Uploaded '.$record['key'].'. Update the content reference, then publish.');
    }

    public function replace(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'image' => ['required', 'file', 'mimes:jpeg,png,webp,gif', 'max:8192'],
            'folder' => ['required', 'string', 'max:120'],
            'old_key' => ['required', 'string', 'max:500'],
            'sheet' => ['nullable', 'string', 'max:60'],
            'cell' => ['nullable', 'string', 'max:20'],
            'alt' => ['nullable', 'string', 'max:200'],
        ]);
        try {
            $record = $this->images->replace($request->file('image')->getRealPath(), $data['folder'], $data['old_key'], $data['alt'] ?? null);
            if (! empty($data['sheet']) && ! empty($data['cell'])) {
                $this->sheets->updateCell($data['sheet'], $data['cell'], $record['key']);
            }
        } catch (RuntimeException $exception) {
            return redirect()->back()->with('error', $exception->getMessage());
        }

        return redirect()->back()->with('success', 'Replaced image. Publish to make it live; the old file is retained for the configured period.');
    }

    public function delete(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string', 'max:500']]);
        $this->images->queueDeletion($data['key']);

        return redirect()->back()->with('success', 'Deletion queued after the retention period.');
    }

    public function cleanup(): RedirectResponse
    {
        $result = $this->images->processDeletions();

        return redirect()->back()->with('success', count($result['deleted']).' old image(s) deleted; '.$result['waiting'].' waiting.');
    }
}
