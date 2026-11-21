<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Publishing dashboard') — Knots & Perl</title>
    <link rel="stylesheet" href="/css/admin.css">
</head>
<body>
<div class="admin">
    <aside class="sidebar">
        <a class="side-brand" href="{{ route('dashboard') }}">Knots &amp; Perl <small>CMS</small></a>
        <nav>
            <a href="{{ route('dashboard') }}" @class(['active' => request()->routeIs('dashboard')])>Overview</a>
            <a href="{{ route('validation') }}" @class(['active' => request()->routeIs('validation')])>Validation</a>
            <a href="{{ route('images.index') }}" @class(['active' => request()->routeIs('images.*')])>Images</a>
            @foreach((array) config('cms.editable_tabs', []) as $tab)
                <a href="{{ route('content.index', $tab) }}" @class(['active' => request()->routeIs('content.*') && request()->route('tab') === $tab])>{{ $tab }}</a>
            @endforeach
        </nav>
        <p class="side-note">This dashboard is for generation and publishing. Site edits happen in the website admin.</p>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="secondary">Log out</button>
        </form>
    </aside>
    <main class="admin-main">
        @if(config('cms.local_mode'))
            <div class="alert alert-success">
                <strong>LOCAL FIXTURE MODE — nothing is live</strong>
                <form method="POST" action="{{ route('local.reset') }}" style="display:inline">
                    @csrf
                    <button type="submit" class="secondary">Reset fixture</button>
                </form>
            </div>
        @endif
        @foreach(['success', 'error'] as $type)
            @if(session($type))
                <div class="alert alert-{{ $type }}">{{ session($type) }}</div>
            @endif
        @endforeach
        @if(session('preview'))
            <div class="alert alert-success">
                Preview {{ session('preview.id') }} generated ({{ session('preview.files') }} files,
                {{ session('preview.warnings') }} warnings).
                <a href="{{ route('publishing.download', session('preview.id')) }}">Download artifact</a>
            </div>
        @endif
        @yield('content')
    </main>
</div>
@yield('scripts')
</body>
</html>
