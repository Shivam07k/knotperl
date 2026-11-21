<header class="site-header" id="site-header">
    <a class="brand" href="/" aria-label="{{ $site['settings']['site_name'] ?? 'Knots & Perl' }} home">
        @if(!empty($site['settings']['site_logo']))
            <img src="{{ r2($site['settings']['site_logo']) }}" alt="{{ $site['settings']['site_name'] ?? 'Knots & Perl' }}" width="36" height="36">
        @else
            <span class="brand-mark">K&amp;P</span>
        @endif
    </a>
    <nav class="desktop-nav" aria-label="Main navigation">
        @include('site.partials.nav-tree', ['items' => $site['navigation']['header'] ?? [], 'depth' => 0])
    </nav>
    <div class="header-actions">
        <button id="theme-toggle" class="icon-button" type="button" aria-label="Toggle theme" aria-pressed="false" data-tip="Theme">◐</button>
        <label class="search-toggle" aria-label="Search products" data-tip="Search">
            <input type="checkbox" id="search-check">
            <span aria-hidden="true">⌕</span>
        </label>
        <button id="menu-toggle" class="icon-button menu-toggle" type="button" aria-expanded="false" aria-controls="mobile-nav" aria-label="Open menu" data-tip="Menu">☰</button>
    </div>
</header>
<div id="search-panel" class="search-panel" hidden>
    <form id="search-form" role="search">
        <label for="search-input">Search the collection</label>
        <input id="search-input" type="search" placeholder="Search..." autocomplete="off">
    </form>
    <div id="search-results" class="search-results" aria-live="polite"></div>
</div>
<nav id="mobile-nav" class="mobile-nav" aria-label="Mobile navigation" hidden>
    @include('site.partials.nav-tree', ['items' => $site['navigation']['header'] ?? [], 'depth' => 0])
</nav>
