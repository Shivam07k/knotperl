@extends('site.layouts.app')

@section('content')
    <div class="detail-panel" id="detail-panel">
        <section class="product-detail">
            <nav class="breadcrumbs" aria-label="Breadcrumb">
                <a href="/">Home</a><span>/</span><a href="/products/">Collection</a><span>/</span><span>{{ $product['product_name'] }}</span>
                <button class="detail-back" type="button" data-detail-close aria-label="Back to collection">← Back</button>
            </nav>
            <div class="product-detail-grid">
                <div class="product-gallery">
                    @if($product['gallery_urls'])
                        @foreach($product['gallery_urls'] as $image)
                            <img src="{{ $image }}" alt="{{ $product['product_name'] }}" loading="lazy">
                        @endforeach
                    @elseif($product['image_url'])
                        <img src="{{ $product['image_url'] }}" alt="{{ $product['product_name'] }}">
                    @else
                        <span class="image-placeholder">K&amp;P</span>
                    @endif
                </div>
                <div class="product-info">
                    <p class="eyebrow">{{ $product['category']['category_name'] ?? '' }}</p>
                    <h1>{{ $product['product_name'] }}</h1>
                    <p class="product-price">
                        @if((float) $product['sale_price'] > 0)
                            <s>{{ $site['settings']['currency'] ?? 'USD' }} {{ number_format((float) $product['regular_price'], 2) }}</s>
                            <strong>{{ $site['settings']['currency'] ?? 'USD' }} {{ number_format((float) $product['sale_price'], 2) }}</strong>
                        @else
                            <strong>{{ $site['settings']['currency'] ?? 'USD' }} {{ number_format((float) $product['regular_price'], 2) }}</strong>
                        @endif
                    </p>
                    <div class="prose">{!! e($product['full_description']) !!}</div>
                    <p class="sku">SKU: {{ $product['SKU'] }}</p>
                    <a class="button" href="mailto:{{ e($site['settings']['contact_email'] ?? '') }}?subject={{ rawurlencode('Enquiry: '.$product['product_name']) }}">Enquire about this piece</a>
                </div>
            </div>
        </section>
        @if($related)
            <section class="section"><div class="section-inner">
                <h2 class="section-title">You may also like</h2>
                <div class="card-grid product-grid">
                    @foreach($related as $item)
                        @include('site.partials.product-card', ['product' => $item])
                    @endforeach
                </div>
            </div></section>
        @endif
    </div>
@endsection
