<div id="intro" class="intro" role="dialog" aria-label="Opening sequence">
    <div id="line"></div>
    <div class="door door-top"></div>
    <div class="door door-bottom"></div>
    <div class="door door-left"></div>
    <div class="door door-right"></div>
    <div class="intro-content">
        <canvas id="intro-canvas" aria-hidden="true"></canvas>
        <div class="intro-brand" id="intro-brand" aria-label="Knots &amp; Perl">
            <span class="o" aria-hidden="true"><span>Knots</span><em>&amp;</em><span>Perl</span></span>
            <span class="liquid" id="liq"><span>Knots</span><em>&amp;</em><span>Perl</span></span>
        </div>
    </div>
</div>
<script>
(function () {
    var entry = window.performance && performance.getEntriesByType ? performance.getEntriesByType('navigation')[0] : null;
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (!entry || entry.type !== 'reload' || reduce) {
        document.getElementById('intro').classList.add('is-done');
    }
})();
</script>
