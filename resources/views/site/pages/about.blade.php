@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>{{ $page['title'] ?? 'About Us' }}</span></nav>
        <h1>{{ $page['title'] ?? 'About Us' }}</h1>
    </header>
    <div class="prose page-content">@clean($page['content'] ?? '')</div>
    @include('site.partials.sections', ['sections' => $site['sections']['about-us'] ?? [], 'page' => $page])
@endsection
