<footer class="site-footer" id="site-footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <span class="brand-mark">{{ $site['settings']['site_name'] ?? 'Knots & Perl' }}</span>
            <p>{{ $site['settings']['site_tagline'] ?? '' }}</p>
        </div>
        <div>
            <h2 class="footer-heading">Explore</h2>
            @include('site.partials.nav-tree', ['items' => $site['navigation']['footer'] ?? [], 'depth' => 0])
            <ul class="nav-list"><li class="nav-item"><a href="/sitemap.html">Sitemap</a></li></ul>
        </div>
        <div>
            <h2 class="footer-heading">Contact</h2>
            <ul class="contact-list">
                @foreach($site['contact'] as $contact)
                    <li>
                        <span class="contact-icon" aria-hidden="true">{{ substr((string) $contact['type'], 0, 1) }}</span>
                        @if(in_array($contact['type'], ['email', 'phone', 'whatsapp'], true))
                            <a href="{{ $contact['type'] === 'email' ? 'mailto:'.e($contact['value']) : ($contact['type'] === 'phone' ? 'tel:'.e(preg_replace('/\D/', '', $contact['value'])) : 'https://wa.me/'.e(preg_replace('/\D/', '', $contact['value']))) }}">{{ $contact['label'] }}: {{ $contact['value'] }}</a>
                        @else
                            <span>{{ $contact['label'] }}: {{ $contact['value'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <p>{{ $site['settings']['copyright_text'] ?? '' }}</p>
        <p>Made slowly. Worn often.</p>
    </div>
</footer>
