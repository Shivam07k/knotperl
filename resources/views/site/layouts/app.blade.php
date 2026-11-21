<!doctype html>
<html lang="{{ $site['settings']['default_language'] ?? 'en' }}" data-theme="{{ $site['settings']['theme'] ?? 'obsidian' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0b0d">
    <title>{{ $seo['title'] }}</title>
    <meta name="description" content="{{ $seo['description'] }}">
    @if($seo['keywords'] ?? '')<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
    <meta name="robots" content="{{ $seo['robots'] }}">
    <link rel="canonical" href="{{ $seo['canonical'] }}">
    <meta property="og:type" content="website">
    <meta property="og:title" content="{{ $seo['og_title'] }}">
    <meta property="og:description" content="{{ $seo['og_description'] }}">
    @if($seo['og_image'])<meta property="og:image" content="{{ $seo['og_image'] }}">@endif
    <meta name="twitter:card" content="summary_large_image">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600&family=Montserrat:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/site.css">
    <link rel="stylesheet" href="/css/theme.css">
    <link rel="stylesheet" href="/assets/vendor/liquid-glass/glass.css">
    <link rel="modulepreload" href="/assets/vendor/three.module.js">
    <link rel="modulepreload" href="/assets/vendor/crystal.js">
    @if($site['settings']['favicon'] ?? '')<link rel="icon" href="{{ r2($site['settings']['favicon']) }}">@endif
    <script type="application/ld+json">{!! json_encode(['@'.'context'=>'https://schema.org','@type'=>'Organization','name'=>$site['settings']['site_name'] ?? 'Knots & Perl','url'=>config('publishing.site_url'),'email'=>$site['settings']['contact_email'] ?? ''], JSON_UNESCAPED_SLASHES) !!}</script>
</head>
<body class="theme-{{ $site['settings']['theme'] ?? 'obsidian' }}"
      data-intro="{{ !empty($site['settings']['enable_intro']) ? 'true' : 'false' }}"
      data-sound="{{ !empty($site['settings']['enable_intro_sound']) ? 'true' : 'false' }}"
      data-countdown="{{ (int) ($site['settings']['countdown_seconds'] ?? 3) }}"
      data-heavy="{{ !empty($site['settings']['enable_heavy_animation']) ? 'true' : 'false' }}"
      data-intro-texture="{{ r2($site['settings']['intro_texture_url'] ?? '') }}"
      data-intro-video="{{ r2($site['settings']['intro_video_url'] ?? '') }}"
      data-contact-endpoint="{{ $site['settings']['form_endpoint'] ?? '' }}"
      data-newsletter-endpoint="{{ $site['settings']['newsletter_endpoint'] ?? '' }}">
    <a class="skip-link" href="#main">Skip to content</a>

    @if(!empty($site['settings']['enable_intro']))
        @include('site.partials.intro')
    @endif

    @include('site.partials.header')

    <main id="main" class="site-main">
        @if(!empty($site['settings']['maintenance_mode']))
            <p class="notice">We are preparing something new. Please check back shortly.</p>
        @endif
        @yield('content')
    </main>

    @include('site.partials.footer')

    <a class="admin-float" href="{{ config('publishing.admin_url') }}" aria-label="Admin panel">Admin</a>
    @php($whatsapp = preg_replace('/\D/', '', (string) ($site['settings']['whatsapp_number'] ?? '')))
    @if($whatsapp !== '')
        <a class="whatsapp-float" href="https://wa.me/{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" aria-label="Chat on WhatsApp"><span class="tooltip">Chat on WhatsApp</span><span aria-hidden="true">WA</span></a>
    @endif

    <div id="slide-overlay" class="slide-overlay" aria-live="polite"></div>

    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/gsap.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/gsap@3.12.5/dist/ScrollTrigger.min.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/three@0.160.0/build/three.min.js" defer></script>
    <script src="/assets/vendor/liquid-glass/html2canvas.min.js" defer></script>
    <script src="/assets/vendor/liquid-glass/container.js" defer></script>
    <script src="/assets/vendor/liquid-glass/button.js" defer></script>
    <script src="/js/premium.js" defer></script>
    <script src="/js/intro.js" defer></script>
    <script src="/js/site.js" defer></script>
</body>
</html>
