(function () {
    'use strict';

    var root = document.documentElement;
    var prefersReduced = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var gsap = window.gsap;

    /* Theme */
    var savedTheme = null;
    try { savedTheme = window.localStorage.getItem('kp-theme'); } catch (e) { /* storage disabled */ }
    applyTheme(savedTheme || root.getAttribute('data-theme') || 'obsidian');
    var themeButton = document.getElementById('theme-toggle');
    if (themeButton) {
        themeButton.addEventListener('click', function () {
            var next = root.getAttribute('data-theme') === 'pearl' ? 'obsidian' : 'pearl';
            applyTheme(next);
            try { window.localStorage.setItem('kp-theme', next); } catch (e) { /* storage disabled */ }
        });
    }
    function applyTheme(theme) {
        root.setAttribute('data-theme', theme);
        if (themeButton) { themeButton.setAttribute('aria-pressed', theme === 'pearl' ? 'true' : 'false'); }
        var themeMeta = document.querySelector('meta[name="theme-color"]');
        if (themeMeta) { themeMeta.setAttribute('content', theme === 'pearl' ? '#f9f5f0' : '#0b0b0d'); }
    }

    /* Sliding gold highlight behind the active / hovered nav link */
    (function () {
        var nav = document.querySelector('.desktop-nav > .nav-list');
        if (!nav || prefersReduced) { return; }
        var links = [].slice.call(nav.children).filter(function (el) { return el.tagName === 'LI'; })
            .map(function (li) { return li.firstElementChild; })
            .filter(function (a) { return a && a.tagName === 'A'; });
        if (!links.length) { return; }

        var highlight = document.createElement('span');
        highlight.className = 'nav-highlight';
        highlight.setAttribute('aria-hidden', 'true');
        nav.appendChild(highlight);

        var normalize = function (value) {
            return (value || '').replace(/index\.html$/, '').replace(/\/+$/, '') || '/';
        };
        var path = normalize(window.location.pathname);
        var active = links.filter(function (a) { return normalize(a.getAttribute('href')) === path; })[0];
        if (active && !active.hasAttribute('aria-current')) { active.setAttribute('aria-current', 'page'); }

        function move(link) {
            if (!link) { return; }
            /* offsetLeft is relative to the positioned LI, so measure against the nav instead. */
            var linkBox = link.getBoundingClientRect();
            var navBox = nav.getBoundingClientRect();
            highlight.style.width = linkBox.width + 'px';
            highlight.style.left = (linkBox.left - navBox.left) + 'px';
        }
        move(active);
        links.forEach(function (link) {
            link.addEventListener('mouseenter', function () { move(link); });
            link.addEventListener('focus', function () { move(link); });
            link.addEventListener('mouseleave', function () { move(active); });
            link.addEventListener('blur', function () { move(active); });
        });
        window.addEventListener('resize', function () { move(active); });
    })();

    /* Mobile navigation */
    var menuButton = document.getElementById('menu-toggle');
    var mobileNav = document.getElementById('mobile-nav');
    if (menuButton && mobileNav) {
        menuButton.addEventListener('click', function () {
            var open = mobileNav.hasAttribute('hidden');
            if (open) { mobileNav.removeAttribute('hidden'); } else { mobileNav.setAttribute('hidden', ''); }
            menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
            document.body.classList.toggle('no-scroll', open);
        });
        mobileNav.addEventListener('click', function (event) {
            if (event.target.closest('a')) {
                mobileNav.setAttribute('hidden', '');
                menuButton.setAttribute('aria-expanded', 'false');
                document.body.classList.remove('no-scroll');
            }
        });
    }

    /* Search over the generated JSON */
    var searchCheck = document.getElementById('search-check');
    var searchPanel = document.getElementById('search-panel');
    var searchInput = document.getElementById('search-input');
    var searchResults = document.getElementById('search-results');
    var products = null;
    if (searchCheck && searchPanel) {
        searchCheck.addEventListener('change', function () {
            var open = searchCheck.checked;
            searchPanel.hidden = !open;
            if (open && searchInput) { searchInput.focus(); }
            if (open && products === null) {
                fetch('/data/products.json').then(function (response) { return response.json(); })
                    .then(function (data) { products = data; renderResults(''); })
                    .catch(function () { products = []; });
            }
        });
        document.addEventListener('click', function (event) {
            if (!searchPanel.hidden && !searchPanel.contains(event.target) && !event.target.closest('.search-toggle')) {
                searchPanel.hidden = true;
                searchCheck.checked = false;
            }
        });
    }
    if (searchInput) {
        searchInput.addEventListener('input', function () { renderResults(searchInput.value.trim().toLowerCase()); });
    }
    function renderResults(term) {
        if (!searchResults || products === null) { return; }
        var matches = products.filter(function (product) {
            return term === '' || (product.product_name + ' ' + product.short_description).toLowerCase().indexOf(term) !== -1;
        }).slice(0, 8);
        searchResults.innerHTML = matches.map(function (product) {
            return '<a class="result" href="/products/' + encodeURIComponent(product.slug) + '/">'
                + '<img src="' + escapeHtml(product.image_url || '') + '" alt="" loading="lazy">'
                + '<span>' + escapeHtml(product.product_name) + '</span></a>';
        }).join('') || '<p>No pieces found.</p>';
    }
    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, function (character) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[character];
        });
    }

    /* Category filters */
    document.querySelectorAll('[data-filter]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            var value = chip.getAttribute('data-filter');
            document.querySelectorAll('[data-filter]').forEach(function (other) { other.classList.remove('is-active'); });
            chip.classList.add('is-active');
            document.querySelectorAll('[data-product-grid] .product-card').forEach(function (card) {
                card.hidden = value !== 'all' && card.getAttribute('data-category') !== value;
            });
        });
    });

    /* Slide-in product details without losing the page underneath */
    var overlay = document.getElementById('slide-overlay');
    var overlayOpen = false;
    document.addEventListener('click', function (event) {
        var link = event.target.closest('a[data-slide-panel]');
        if (!link || !overlay) { return; }
        event.preventDefault();
        var href = link.getAttribute('href');
        fetch(href, { headers: { 'X-Requested-With': 'fetch' } })
            .then(function (response) { return response.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var panel = doc.getElementById('detail-panel');
                if (!panel) { window.location.href = href; return; }
                overlay.innerHTML = panel.outerHTML;
                overlay.classList.remove('is-closing-left');
                overlay.classList.add('is-open');
                overlayOpen = true;
                window.history.pushState({ panel: true }, '', href);
                if (gsap && !prefersReduced) {
                    gsap.fromTo(overlay.querySelector('.product-detail'), { x: 60, opacity: 0 }, { x: 0, opacity: 1, duration: 0.6, ease: 'power3.out' });
                }
            })
            .catch(function () { window.location.href = href; });
    });
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-detail-close]')) { closePanel(); }
    });
    window.addEventListener('popstate', function () { if (overlayOpen) { closePanel(true); } });
    function closePanel(skipHistory) {
        if (!overlay || !overlayOpen) { return; }
        overlayOpen = false;
        var panel = overlay.querySelector('.product-detail');
        if (gsap && panel && !prefersReduced) {
            gsap.to(panel, { x: -60, opacity: 0, duration: .45, ease: 'power3.in' });
        }
        overlay.classList.remove('is-open');
        overlay.classList.add('is-closing-left');
        if (!skipHistory) { window.history.back(); }
        window.setTimeout(function () {
            if (!overlayOpen) { overlay.innerHTML = ''; overlay.classList.remove('is-closing-left'); }
        }, 720);
    }

    /* Reveal on scroll and draggable carousels */
    var reveals = document.querySelectorAll('.section-inner .card-grid, .section-inner .prose, .page-header');
    if ('IntersectionObserver' in window && !prefersReduced) {
        reveals.forEach(function (element) { element.classList.add('slide-fade'); });
        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) { entry.target.classList.add('is-visible'); observer.unobserve(entry.target); }
            });
        }, { threshold: 0.08 });
        reveals.forEach(function (element) { observer.observe(element); });
    }
    document.querySelectorAll('[data-carousel]').forEach(function (rail) {
        var down = false, startX = 0, startScroll = 0;
        rail.addEventListener('pointerdown', function (event) {
            down = true; startX = event.clientX; startScroll = rail.scrollLeft;
            rail.classList.add('is-dragging'); rail.setPointerCapture(event.pointerId);
        });
        rail.addEventListener('pointermove', function (event) {
            if (down) { rail.scrollLeft = startScroll - (event.clientX - startX); }
        });
        rail.addEventListener('pointerup', function () { if (down) { down = false; settleCarousel(rail); } });
        rail.addEventListener('pointercancel', function () { if (down) { down = false; settleCarousel(rail); } });
        rail.addEventListener('scroll', function () {
            var shift = Math.max(-1, Math.min(1, rail.scrollLeft / 300));
            rail.style.setProperty('--parallax', String(shift));
        }, { passive: true });
    });
    function settleCarousel(rail) {
        var cards = rail.querySelectorAll('.product-card');
        if (!cards.length) { rail.classList.remove('is-dragging'); return; }
        var railLeft = rail.getBoundingClientRect().left;
        var nearest = cards[0], distance = Infinity;
        cards.forEach(function (card) {
            var gap = Math.abs(card.getBoundingClientRect().left - railLeft);
            if (gap < distance) { distance = gap; nearest = card; }
        });
        var target = nearest.getBoundingClientRect().left - railLeft + rail.scrollLeft;
        rail.classList.remove('is-dragging');
        if (rail.scrollTo) { rail.scrollTo({ left: target, behavior: prefersReduced ? 'auto' : 'smooth' }); }
        else { rail.scrollLeft = target; }
    }

    /* Forms post to owner-configured collectors; the placeholder stays when none is set. */
    var contactEndpoint = document.body.getAttribute('data-contact-endpoint') || '';
    var newsletterEndpoint = document.body.getAttribute('data-newsletter-endpoint') || '';
    function addFormNote(form, text) {
        var note = document.createElement('small');
        note.textContent = text;
        form.appendChild(note);
    }
    document.querySelectorAll('[data-newsletter], [data-contact-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var isContact = form.hasAttribute('data-contact-form');
            var endpoint = isContact ? contactEndpoint : newsletterEndpoint;
            if (!endpoint) {
                addFormNote(form, 'Thank you — connect a form provider to receive this message.');
                form.reset();
                return;
            }
            fetch(endpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    type: isContact ? 'contact' : 'newsletter',
                    fields: Object.fromEntries(new FormData(form)),
                    page: location.pathname,
                    submitted_at: new Date().toISOString()
                })
            }).then(function (response) {
                if (response.ok) {
                    addFormNote(form, "Thanks — we'll be in touch.");
                    form.reset();
                } else {
                    addFormNote(form, 'Could not send — please try again.');
                }
            }).catch(function () {
                addFormNote(form, 'Could not send — please try again.');
            });
        });
    });
})();
