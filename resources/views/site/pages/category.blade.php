@extends('site.layouts.app')

@section('content')
    <header class="page-header">
        <nav class="breadcrumbs" aria-label="Breadcrumb"><a href="/">Home</a><span>/</span><a href="/products/">Collection</a><span>/</span><span>{{ $category['category_name'] }}</span></nav>
        <h1>{{ $category['category_name'] }}</h1>
        <p>{!! e($category['description']) !!}</p>
    </header>
    <section class="section"><div class="section-inner">
        <div class="card-grid product-grid" data-product-grid>
            @foreach($products as $product)
                @include('site.partials.product-card', ['product' => $product])
            @endforeach
        </div>
        @if($products === [])<p>No pieces in this collection yet.</p>@endif
    </div></section>
@endsection
