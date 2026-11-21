@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>{{ $page['title'] ?? 'Services' }}</span></nav>
        <h1>{{ $page['title'] ?? 'Services' }}</h1>
    </header>
    <div class="prose page-content">@clean($page['content'] ?? '')</div>
    <section class="section"><div class="section-inner">
        <div class="card-grid services-grid">
            @foreach($site['services'] as $service)
                <article class="service-card">
                    @if($service['image'])<img src="{{ r2($service['image']) }}" alt="" loading="lazy">@endif
                    <h2>{{ $service['title'] }}</h2>
                    <p>{!! e($service['description']) !!}</p>
                </article>
            @endforeach
        </div>
    </div></section>
    @include('site.partials.sections', ['sections' => $site['sections']['services'] ?? [], 'page' => $page])
@endsection
