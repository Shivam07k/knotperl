# Close Design Gaps Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the eight remaining gaps between the generated site and the approved interactive brief.

**Architecture:** Extend the existing static Laravel generator and vanilla GSAP/Three.js frontend. Add a 2D code-rain canvas, refine particle geometry, add CSS/WebAudio polish, generate an HTML sitemap, minify output assets, and add a CI build path fed by Google secrets.

**Tech Stack:** Laravel 12, PHP 8.3, Blade, GSAP, Three.js, WebAudio, `matthiasmullie/minify`, GitHub Actions, Cloudflare Pages.

**Spec:** `docs/superpowers/specs/2026-11-19-close-design-gaps-design.md`

## Global Constraints

- No database, Eloquent, or migrations.
- Visitors receive only static files; no Google/R2 credentials in `site/`.
- Keep the existing 12-tab schema and static generation boundary.
- Respect `prefers-reduced-motion` and keep the intro skippable.
- Tests must pass with mocked Google/Cloudflare services.

## Review Focus

- Narrow phones must bypass the intro entirely without breaking skip/brand/door logic.
- Browser autoplay policies must not produce uncaught errors when sound is enabled.
- Minified JS must preserve the `r2()` helper usage in Blade-rendered output and all CDN script tags.
- `GOOGLE_TOKEN_JSON` must never be copied into `site/`.
- Manual CI build must fail cleanly when Google secrets are absent.

---

### Task 1: Intro motion polish

**Files:**
- Modify: `public/js/intro.js`
- Modify: `public/css/site.css`
- Modify: `resources/views/site/partials/intro.blade.php`
- Modify: `resources/views/site/layouts/app.blade.php`
- Modify: `resources/cms/schema.json`
- Modify: `resources/cms/template.json`
- Modify: `app/Services/CMS/ContentBuilderService.php`
- Test: `tests/Feature/StaticSiteGeneratorTest.php`

- [x] **Step 1: Assert intro sound flag and code canvas are generated**

Add assertions to `test_it_generates_pages_json_and_hosting_files` that `index.html` contains `id="intro-code"` and `data-sound="false"`.

- [x] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: FAIL on the new assertions.

- [x] **Step 3: Add the Sheets setting and markup**

Add `enable_intro_sound` to schema defaults and the Settings template rows; include it in the boolean conversion list in `ContentBuilderService::settings()`. Add `data-sound` to `<body>` and `<canvas id="intro-code">` inside the intro partial.

- [x] **Step 4: Reshape particles, add code rain, audio, mobile skip, and chromatic CSS**

In `intro.js`, split target positions into a sphere cluster and diamond cluster, add a 2D digit-rain renderer with its own RAF/cleanup, call a guarded `playCue()` on brand reveal, and call `finish()` immediately when `window.innerWidth <= 480`. In `site.css`, add `.intro-code`, chromatic text-shadow keyframes, and `.slide-overlay.is-closing-left`.

- [x] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: PASS.

### Task 2: Panel close direction and carousel parallax

**Files:**
- Modify: `public/js/site.js`
- Modify: `public/css/site.css`

- [x] **Step 1: Add a JS syntax check**

Run `node --check public/js/site.js` after the change.

- [x] **Step 2: Close panels leftward by default**

Change `closePanel(skipHistory, direction)` to add `.is-closing-left` for the default `left` direction, remove it on open, and clean it up after the transition.

- [x] **Step 3: Add scroll-driven parallax**

On `[data-carousel]` scroll, set `--parallax` from scroll position; use it in `.product-image img` transforms under `[data-carousel]`.

- [x] **Step 4: Verify syntax and existing tests**

Run: `node --check public/js/site.js && php artisan test --compact`
Expected: PASS.

### Task 3: HTML sitemap

**Files:**
- Create: `resources/views/site/pages/sitemap.blade.php`
- Modify: `app/Services/CMS/StaticSiteGenerator.php`
- Modify: `resources/views/site/partials/footer.blade.php`
- Test: `tests/Feature/StaticSiteGeneratorTest.php`

- [x] **Step 1: Assert `sitemap.html` exists**

Add `$this->assertFileExists($target.'/sitemap.html');` to the generator test.

- [x] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: FAIL on the missing file.

- [x] **Step 3: Add the view and generator write**

Create the Blade view using `site.layouts.app`, accept `$links`, and write it in `render()` with route `/sitemap.html` so it is included in `sitemap.xml`. Add a footer link.

- [x] **Step 4: Run the test and confirm it passes**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: PASS.

### Task 4: Output minification

**Files:**
- Modify: `composer.json`
- Modify: `app/Services/CMS/StaticSiteGenerator.php`
- Test: `tests/Feature/StaticSiteGeneratorTest.php`

- [x] **Step 1: Assert generated CSS is minified**

Add an assertion that generated `css/site.css` does not contain the source comment `/* Knots & Perl`.

- [x] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: FAIL.

- [x] **Step 3: Install the minifier and use it in `copyAssets()`**

Run: `composer require matthiasmullie/minify:^1.3`
Use `MatthiasMullie\Minify\CSS` and `JS` for `.css` and `.js` files; keep other files copied verbatim.

- [x] **Step 4: Run the test and confirm it passes**

Run: `php artisan test --filter=StaticSiteGeneratorTest`
Expected: PASS.

### Task 5: CI build from Sheets

**Files:**
- Modify: `config/google.php`
- Modify: `app/Services/Google/GoogleAuthService.php`
- Modify: `.env.example`
- Modify: `.github/workflows/deploy.yml`
- Modify: `docs/ENVIRONMENT_CONFIG.md`
- Modify: `docs/GITHUB_SETUP.md`
- Test: `tests/Feature/GoogleAuthServiceTest.php`

- [x] **Step 1: Assert env token seeding**

Add a test that sets `google.token_path` to a temp file and `google.token_json` to a JSON token, then asserts `isConnected()` is true and the encrypted token file exists.

- [x] **Step 2: Run the test and confirm it fails**

Run: `php artisan test --filter=GoogleAuthServiceTest`
Expected: FAIL.

- [x] **Step 3: Implement env token seeding**

Add `google.token_json` config and seed the encrypted token file from it in `GoogleAuthService::readToken()` when no token file exists.

- [x] **Step 4: Add workflow build job and docs**

Add a `workflow_dispatch` input `build_from_sheets` (`false`/`true`) and a `build` job that installs PHP/Composer, runs `cms:publish --no-deploy` and `cms:export`, and uploads `site/`. Make deploy consume the artifact when that job ran. Document `GOOGLE_TOKEN_JSON` and the manual build trigger.

- [x] **Step 5: Run the test and confirm it passes**

Run: `php artisan test --filter=GoogleAuthServiceTest`
Expected: PASS.

### Task 6: Regenerate and final verification

**Files:**
- Modify: `site/**` (generated)
- Modify: `docs/SPREADSHEET_SCHEMA.md`

- [x] **Step 1: Run the full suite**

Run: `php artisan test`
Expected: all tests pass.

- [x] **Step 2: Regenerate the committed site**

Run the mocked local publish smoke script, then `php artisan cms:export`.

- [x] **Step 3: Static verification**

Run the JSON/JS/view verifier and PHP parser. Confirm no credentials or `<?php` leaks in `site/`.

- [x] **Step 4: Commit**

No git repository exists; report the file set instead of committing.
