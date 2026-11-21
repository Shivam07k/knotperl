@extends('layouts.dashboard')

@section('title', 'Overview')

@section('content')
    <h1>Website overview</h1>

    @if(config('cms.local_mode'))
        <div class="panel">
            <h2>Local fixture</h2>
            <p>Edits are saved to the working fixture. Nothing is published to the live site.</p>
            <form method="POST" action="{{ route('local.reset') }}">
                @csrf
                <button type="submit" class="secondary">Reset fixture</button>
            </form>
        </div>
    @endif

    <div class="grid">
        <div class="metric"><span>Site</span><strong>{{ config('publishing.site_url') ?: 'Not configured' }}</strong></div>
        <div class="metric"><span>R2</span><strong>{{ $r2Status ?? 'unavailable' }}</strong></div>
        <div class="metric"><span>Publish mode</span><strong>{{ $mode }}</strong></div>
        <div class="metric"><span>Last publish</span><strong>{{ $status['manifest']['published_at'] ?? 'Never' }}</strong></div>
    </div>

    <div class="panel" style="margin-top:1.4rem">
        <h2>Content</h2>
        <p>These tabs show the stored content file. Use the website admin to make day-to-day edits.</p>
        <div class="inline">
            @foreach((array) config('cms.editable_tabs', []) as $tab)
                <a class="button secondary" href="{{ route('content.index', $tab) }}">{{ $tab }}</a>
            @endforeach
        </div>
    </div>

    <div class="panel">
        <h2>Publishing</h2>
        <p>Generation is validated first. A failed deployment never replaces the live site.</p>
        <div class="inline">
            @unless(config('cms.local_mode'))
                <form method="POST" action="{{ route('publishing.publish') }}">
                    @csrf
                    <button type="submit">Publish website</button>
                </form>
            @endunless
            <form method="POST" action="{{ route('publishing.publish') }}">
                @csrf
                <input type="hidden" name="local_only" value="1">
                <button type="submit" class="secondary">Generate locally (no deploy)</button>
            </form>
            <form method="POST" action="{{ route('publishing.preview') }}">
                @csrf
                <button type="submit" class="secondary">Preview &amp; download</button>
            </form>
        </div>
        @if(!empty($status['manifest']['deployment']['status']))
            <p>Last deployment: <span class="badge">{{ $status['manifest']['deployment']['status'] }}</span>
                via {{ $status['manifest']['deployment']['mode'] }}</p>
        @endif
    </div>

    @if(($status['live_exists'] ?? false) && ! config('cms.local_mode'))
        <div class="panel">
            <h2>Rollback</h2>
            <p>Restores the most recent snapshot and redeploys it.</p>
            <form method="POST" action="{{ route('publishing.rollback') }}">
                @csrf
                <button type="submit" class="secondary">Roll back to previous deployment</button>
            </form>
        </div>
    @endif

    <div class="panel">
        <h2>Publishing history</h2>
        @forelse($status['history'] ?? [] as $entry)
            <table>
                <tr><th>Time</th><th>ID</th><th>Status</th><th>Deployment</th></tr>
                <tr>
                    <td>{{ $entry['at'] ?? $entry['published_at'] ?? '' }}</td>
                    <td><code>{{ $entry['id'] ?? '' }}</code></td>
                    <td><span class="badge {{ ($entry['status'] ?? '') === 'success' ? 'ok' : 'bad' }}">{{ $entry['status'] ?? '' }}</span></td>
                    <td>{{ $entry['deployment']['mode'] ?? '-' }} @if(!empty($entry['error'])){{ $entry['error'] }}@endif</td>
                </tr>
            </table>
        @empty
            <p>No publishing activity yet.</p>
        @endforelse
    </div>

    <div class="panel">
        <h2>Tools</h2>
        <div class="inline">
            <a class="button" href="{{ route('validation') }}">Validate content</a>
            <a class="button" href="{{ route('images.index') }}">Manage images</a>
        </div>
    </div>
@endsection
