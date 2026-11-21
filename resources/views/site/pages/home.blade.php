@extends('site.layouts.app')

@section('content')
    @foreach($site['banners'] as $banner)
        <aside class="banner">
            <picture>
                @if($banner['mobile_image'])<source media="(max-width: 640px)" srcset="{{ r2($banner['mobile_image']) }}">@endif
                <img src="{{ r2($banner['desktop_image']) }}" alt="{{ $banner['title'] }}" loading="lazy">
            </picture>
            <div class="banner-copy">
                <p class="eyebrow">{{ $banner['subtitle'] }}</p>
                <h1>{{ $banner['title'] }}</h1>
                <p>{{ $banner['description'] }}</p>
                <a class="button" href="{{ e($banner['button_url']) }}">{{ $banner['button_text'] }}</a>
            </div>
        </aside>
    @endforeach

    @include('site.partials.sections', ['sections' => $site['sections'][''] ?? [], 'page' => $page])

    @if(($site['sections'][''] ?? []) === [])
        <section class="section section-hero">
            <div class="section-inner">
                <p class="eyebrow">{{ $site['settings']['site_tagline'] ?? '' }}</p>
                <h1 class="section-title">{{ $site['settings']['site_name'] ?? 'Knots & Perl' }}</h1>
            </div>
        </section>
        <section class="section">
            <div class="section-inner">
                <div class="card-grid product-grid">
                    @foreach(array_slice($site['featured_products'], 0, 8) as $product)
                        @include('site.partials.product-card', ['product' => $product])
                    @endforeach
                </div>
            </div>
        </section>
    @endif
@endsection
