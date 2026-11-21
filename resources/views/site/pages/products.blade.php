@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><span>Collections</span></nav>
        <h1>{{ $page['title'] ?? 'The Collection' }}</h1>
        <p>{{ !empty($page['content']) ? strip_tags($page['content']) : 'Every piece, made slowly.' }}</p>
    </header>
    @include('site.partials.sections', ['sections' => $site['sections']['products'] ?? [], 'page' => $page])

    <section class="section"><div class="section-inner">
        <div class="filters" data-filters>
            <button type="button" class="filter-chip is-active" data-filter="all">All</button>
            @foreach($site['categories'] as $category)
                <button type="button" class="filter-chip" data-filter="{{ e($category['slug']) }}">{{ $category['category_name'] }}</button>
            @endforeach
        </div>
        <div class="card-grid product-grid" data-product-grid>
            @foreach($products as $product)
                @include('site.partials.product-card', ['product' => $product])
            @endforeach
        </div>
        @if($pagination['total'] > 1)
            <nav class="pagination" aria-label="Pagination">
                @for($i = 1; $i <= $pagination['total']; $i++)
                    @php($href = $i === 1 ? $pagination['base'].'/' : $pagination['base'].'/page/'.$i.'/')
                    <a href="{{ $href }}" @if($i === $pagination['current']) aria-current="page" @endif>{{ $i }}</a>
                @endfor
            </nav>
        @endif
    </div></section>
@endsection
