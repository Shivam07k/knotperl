@extends('layouts.dashboard')

@section('title', 'Images')

@section('content')
    <h1>Cloudflare R2 images</h1>

    <div class="panel">
        <h2>Upload</h2>
        <form method="POST" action="{{ route('images.upload') }}" enctype="multipart/form-data">
            @csrf
            <label>File <input type="file" name="image" accept="image/jpeg,image/png,image/webp,image/gif" required></label>
            <label>Folder <input name="folder" value="products" required placeholder="products/pr001"></label>
            <label>Alt text <input name="alt" maxlength="200"></label>
            <button type="submit">Upload</button>
        </form>
    </div>

    <div class="panel">
        <h2>Search / replace</h2>
        <form method="GET" action="{{ route('images.index') }}" class="inline">
            <label>Prefix <input name="prefix" value="{{ $prefix }}" placeholder="products/"></label>
            <label>Name contains <input name="q" value="{{ $query }}"></label>
            <button type="submit">Search</button>
        </form>
        <table style="margin-top:1rem">
            <tr><th>Key</th><th>Size</th><th>Preview</th><th>Replace</th><th>Delete</th></tr>
            @forelse($objects as $object)
                <tr>
                    <td><code>{{ $object['key'] }}</code></td>
                    <td>{{ round($object['size'] / 1024) }} KB</td>
                    <td><img src="{{ $object['url'] }}" alt="" width="48" height="48" loading="lazy" style="object-fit:cover"></td>
                    <td>
                        <form method="POST" action="{{ route('images.replace') }}" enctype="multipart/form-data">
                            @csrf
                            <input type="hidden" name="old_key" value="{{ $object['key'] }}">
                            <input type="hidden" name="folder" value="{{ dirname($object['key']) === '.' ? '' : dirname($object['key']) }}">
                            <input type="file" name="image" required>
                            <input name="sheet" placeholder="Products" aria-label="Sheet name">
                            <input name="cell" placeholder="K2" aria-label="Cell">
                            <button type="submit">Replace</button>
                        </form>
                    </td>
                    <td>
                        <form method="POST" action="{{ route('images.delete') }}">
                            @csrf
                            <input type="hidden" name="key" value="{{ $object['key'] }}">
                            <button type="submit" class="secondary">Queue delete</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5">No objects found.</td></tr>
            @endforelse
        </table>
    </div>

    <div class="panel">
        <h2>Pending deletion queue</h2>
        @forelse($queue as $entry)
            <p><code>{{ $entry['key'] }}</code> — after {{ $entry['delete_after'] }}</p>
        @empty
            <p>Nothing queued.</p>
        @endforelse
        <form method="POST" action="{{ route('images.cleanup') }}">
            @csrf
            <button type="submit" class="secondary">Process expired deletions</button>
        </form>
    </div>
@endsection
