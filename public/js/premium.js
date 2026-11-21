/* Premium glass CTA — vendored liquid-glass-js.
   Upgrades the homepage hero CTA into a refracting glass pill once the intro
   has cleared, so html2canvas samples the real page rather than the overlay. */
(function () {
    'use strict';

    function ready() {
        if (typeof Button === 'undefined' || typeof html2canvas === 'undefined') { return false; }
        var intro = document.getElementById('intro');
        return !intro || intro.classList.contains('is-done');
    }

    function upgrade() {
        var host = document.querySelector('.hero-cta');
        if (!host || !host.firstElementChild) { return; }
        var link = host.firstElementChild;
        if (link.tagName !== 'A') { return; }

        var button = new Button({
            text: (link.textContent || 'Explore').trim(),
            size: '15',
            type: 'pill',
            tintOpacity: 0.25,
            onClick: function () { window.location.href = link.getAttribute('href') || '/products'; }
        });
        button.element.classList.add('premium-cta');
        host.replaceChild(button.element, link);
    }

    var tries = 0;
    var timer = window.setInterval(function () {
        if (ready()) {
            window.clearInterval(timer);
            try { upgrade(); } catch (e) { /* decorative effect — never break the page */ }
        } else if (++tries > 60) {
            window.clearInterval(timer);
        }
    }, 200);
})();
