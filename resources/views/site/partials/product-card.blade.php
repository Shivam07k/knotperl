@php($currency = $site['settings']['currency'] ?? 'USD')
<article class="product-card" data-product-id="{{ $product['product_id'] }}" data-name="{{ strtolower($product['product_name']) }}" data-category="{{ e($product['category']['slug'] ?? '') }}">
    <a class="product-card-link" href="/products/{{ e($product['slug']) }}/" data-slide-panel="/products/{{ e($product['slug']) }}/">
        <div class="product-image">
            @if(!empty($product['image_url']))
                <img src="{{ $product['image_url'] }}" alt="{{ $product['image_alt'] ?: $product['product_name'] }}" loading="lazy" width="960" height="1200">
            @else
                <span class="image-placeholder" aria-hidden="true">K&amp;P</span>
            @endif
            @if(!empty($product['featured']))<span class="badge">Featured</span>@endif
        </div>
        <div class="product-meta">
            <h3>{{ $product['product_name'] }}</h3>
            <p class="product-category">{{ $product['category']['category_name'] ?? '' }}</p>
            <p class="product-price">
                @if((float) $product['sale_price'] > 0)
                    <s>{{ $currency }} {{ number_format((float) $product['regular_price'], 2) }}</s>
                    <strong>{{ $currency }} {{ number_format((float) $product['sale_price'], 2) }}</strong>
                @else
                    <strong>{{ $currency }} {{ number_format((float) $product['regular_price'], 2) }}</strong>
                @endif
            </p>
        </div>
    </a>
</article>
