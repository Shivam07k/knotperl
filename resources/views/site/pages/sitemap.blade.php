@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>Sitemap</span></nav>
        <h1>Sitemap</h1>
    </header>
    <div class="prose page-content">
        <ul class="sitemap-list">
            @foreach($links as $link)
                <li><a href="{{ $link }}">{{ $link === '/' ? 'Home' : $link }}</a></li>
            @endforeach
        </ul>
    </div>
@endsection
