# Close Design Gaps — Design

**Goal:** Align the generated Knots & Perl site with the approved interactive brief without changing the static-generation architecture.

**Approaches considered:**
1. Full WebGL/shader rewrite — higher fidelity, high effort and risk.
2. Pure hand-rolled CSS/JS with no new dependencies — brittle minification and CI credential handling.
3. Extend the existing Laravel + GSAP/Three.js stack — chosen. Keeps the static/CDN boundary and adds only one small PHP minifier package.

**Decisions:**
- Replace the abstract particle spiral with a pearl sphere + faceted gem target, plus a lightweight 2D falling-digit canvas.
- Add opt-in WebAudio cue (autoplay rules prevent forcing sound), CSS chromatic text on brand reveal, leftward detail-panel close, and carousel parallax.
- Auto-skip the intro on narrow phones.
- Generate `sitemap.html` and link it from the footer.
- Minify generated CSS/JS with `matthiasmullie/minify`.
- Add a `workflow_dispatch` build-from-Sheets path using `GOOGLE_TOKEN_JSON` and existing spreadsheet/client secrets; push events still deploy the committed `site/`.
- Update schema/template/docs for `enable_intro_sound` and new CI secrets.

**Non-goals:** full shader rewrite, new JS framework, forced audio, database, runtime PHP on Cloudflare Pages.
