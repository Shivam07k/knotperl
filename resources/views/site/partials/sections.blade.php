@foreach($sections as $section)
    @php
        $type = (string) $section['section_type'];
        $ids = array_values(array_filter(array_map('trim', explode('|', (string) $section['section_data']))));
    @endphp
    <section class="section section-{{ \Illuminate\Support\Str::slug($type) }}" data-section="{{ $type }}">
        <div class="section-inner">
            @if($section['section_subtitle'] ?? '')<p class="eyebrow">{{ $section['section_subtitle'] }}</p>@endif
            @if($section['section_title'] ?? '')<h2 class="section-title">{{ $section['section_title'] }}</h2>@endif
            @if($section['section_description'] ?? '')<p class="section-description">{{ $section['section_description'] }}</p>@endif

            @switch($type)
                @case('Hero')
                    <p class="hero-cta"><a class="button" href="/products">Explore the collection</a></p>
                    @break
                @case('Featured Categories')
                    <div class="card-grid categories-grid">
                        @foreach($site['categories'] as $category)
                            @if($ids === [] || in_array((string) $category['category_id'], $ids, true))
                                <a class="category-card" href="/category/{{ e($category['slug']) }}/">
                                    <span>{{ $category['category_name'] }}</span>
                                    @if(!empty($category['category_image']))<img src="{{ r2($category['category_image']) }}" alt="{{ $category['image_alt'] ?? '' }}" loading="lazy">@endif
                                </a>
                            @endif
                        @endforeach
                    </div>
                    @break
                @case('Featured Products')
                @case('New Arrivals')
                @case('Product Grid')
                    @php($gridProducts = $ids === [] ? $site['products'] : array_values(array_filter($site['products'], fn($p) => in_array((string) $p['product_id'], $ids, true))))
                    <div class="card-grid product-grid" data-carousel>
                        @foreach(array_slice($gridProducts, 0, 8) as $product)
                            @include('site.partials.product-card', ['product' => $product])
                        @endforeach
                    </div>
                    @break
                @case('About Us')
                    <div class="prose">@clean($page['content'] ?? '')</div>
                    @break
                @case('Services')
                    <div class="card-grid services-grid">
                        @foreach($site['services'] as $service)
                            <article class="service-card">
                                <h3>{{ $service['title'] }}</h3>
                                <p>{!! e($service['description']) !!}</p>
                            </article>
                        @endforeach
                    </div>
                    @break
                @case('Testimonials')
                    <div class="card-grid testimonial-grid">
                        @foreach($site['testimonials'] as $testimonial)
                            <blockquote class="testimonial-card">
                                <p>“{{ $testimonial['testimonial_text'] }}”</p>
                                <footer>{{ $testimonial['customer_name'] }} <span>{{ str_repeat('★', (int) $testimonial['rating']) }}</span></footer>
                            </blockquote>
                        @endforeach
                    </div>
                    @break
                @case('Statistics')
                    <div class="stats-grid">
                        @foreach($ids as $stat)
                            @php($parts = array_pad(explode(':', $stat, 2), 2, ''))
                            <div><strong>{{ trim($parts[0]) }}</strong><span>{{ trim($parts[1]) }}</span></div>
                        @endforeach
                    </div>
                    @break
                @case('Image Gallery')
                    <div class="gallery-grid">
                        @foreach($ids as $image)
                            <img src="{{ r2($image) }}" alt="" loading="lazy">
                        @endforeach
                    </div>
                    @break
                @case('Promotional Banner')
                    @foreach($site['banners'] as $banner)
                        <a class="promo-banner" href="{{ e($banner['button_url']) }}">
                            <picture>
                                @if($banner['mobile_image'])<source media="(max-width: 640px)" srcset="{{ r2($banner['mobile_image']) }}">@endif
                                <img src="{{ r2($banner['desktop_image']) }}" alt="{{ $banner['title'] }}" loading="lazy">
                            </picture>
                            <span class="promo-copy"><strong>{{ $banner['title'] }}</strong>{{ $banner['button_text'] }} →</span>
                        </a>
                    @endforeach
                    @break
                @case('Contact Information')
                    @include('site.partials.contact-details', ['contact' => $site['contact']])
                    @break
                @case('Newsletter')
                    <form class="newsletter" data-newsletter>
                        <label for="newsletter-email-{{ $section['section_id'] }}">Email address</label>
                        <input id="newsletter-email-{{ $section['section_id'] }}" name="email" type="email" required placeholder="you@example.com">
                        <button class="button" type="submit">Join</button>
                        @if(empty($site['settings']['newsletter_endpoint']))
                            <small>The form is a placeholder until an email provider is connected.</small>
                        @endif
                    </form>
                    @break
                @case('Custom HTML')
                    <div class="prose custom-html">@clean($section['section_description'])</div>
                    @break
            @endswitch
        </div>
    </section>
@endforeach
