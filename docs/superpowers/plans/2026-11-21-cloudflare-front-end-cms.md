# Front-end CMS on Cloudflare Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Google Sheets and the local PHP server with a browser admin, a Cloudflare Worker and a GitHub Action that publishes the unchanged visitor site to Cloudflare Pages.

**Architecture:** Content becomes one JSON file in the shape the existing generator already consumes. A Cloudflare Worker holds all secrets and exposes content/image/publish endpoints to a static admin app hosted on Cloudflare Pages. GitHub Actions runs the existing Laravel generator with a JSON content source, so no visitor template, CSS or JS is rewritten.

**Tech Stack:** PHP 8.3 / Laravel 12, existing Blade generator, Cloudflare Workers (vanilla JS, Web Crypto), Cloudflare R2, Cloudflare Pages Direct Upload, GitHub Actions, vanilla JS admin app, Node 22 `node:test`.

**Spec:** `docs/superpowers/specs/2026-11-21-cloudflare-front-end-cms-design.md`

## Global Constraints

- PHP 8.3 executable: `C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe`. Use `& $php83 artisan test` — `artisan serve` cannot bind on this host.
- Node v22.19.0 available; Worker/admin tests use `node --test` with no added npm dependencies.
- No git repository exists. Replace every "Commit" step with a dated entry in `.superpowers/sdd/2026-11-21-cloudflare-front-end-cms/progress.md` listing files touched and verification output.
- Visitor site is frozen: no edits to `resources/views/site/**`, `site/css/**`, `site/js/**`, intro timing, nav z-index or carousels.
- Content JSON shape must match `tests/Fixtures/sheet-data.json` exactly: an object keyed by the 12 tab names, each an array of row objects.
- Image fields store R2 keys, not full URLs. `R2StorageService::url()` stays unchanged.
- Secrets (Cloudflare API token, R2 keys, GitHub token) must never appear in the browser app or in `site/**`.
- Google remains available until Task 3 (one-time import) has run successfully.

## Review Focus

- **Missing or malformed content JSON** — publish must fail with a clear error and leave the live build untouched. Test: `tests/Feature/JsonContentSourceTest.php::test_invalid_content_json_blocks_publish_without_touching_live_build` (Task 2).
- **Wrong admin password / expired session** — no content read, write or publish may succeed. Test: `worker/test/worker.test.js` rejects a bad password and an expired session (Task 4).
- **Oversized or non-image upload** — rejected before any R2 write. Test: `worker/test/worker.test.js` oversized file and wrong MIME (Task 4).
- **Publish requested without GitHub token / before import** — admin receives a visible error, previous live site unchanged. Test: `worker/test/worker.test.js` publish without token returns 503 without calling GitHub (Task 4).
- **Edited content with duplicate slugs or broken references** — validation blocks publish. Test: `tests/Feature/JsonContentSourceTest.php` invalid-reference case (Task 2).

---

### Task 1: JSON content source

**Files:**
- Modify: `config/cms.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Feature/JsonContentSourceTest.php`

**Interfaces:**
- Consumes: `LocalGoogleSheetsService` (reads/writes `cms.local_fixture_work`, seeds from `cms.local_fixture_source`).
- Produces: `cms.content_source` (`sheets`|`json`) and `cms.local_fixture_source` env override `CMS_CONTENT_JSON`; with `content_source=json`, `GoogleSheetsService` resolves to the file-backed implementation while `R2StorageService` and `PagesDeploymentService` keep their real bindings when `cms.local_mode` is false.

- [ ] **Step 1: Write the failing test**

`tests/Feature/JsonContentSourceTest.php`:

```php
public function test_json_content_source_binds_file_backed_sheets_but_keeps_real_cloudflare_services(): void
{
    config()->set('cms.local_mode', false);
    config()->set('cms.content_source', 'json');
    config()->set('cms.local_fixture_source', $this->tempPath('content.json'));
    config()->set('cms.local_fixture_work', $this->tempPath('content-work.json'));
    file_put_contents((string) config('cms.local_fixture_source'), json_encode([
        'Settings' => [['__row' => 2, 'key' => 'site_name', 'value' => 'Json Brand']],
    ]));

    $sheets = $this->app->make(\App\Services\Google\GoogleSheetsService::class);

    $this->assertInstanceOf(\App\Services\Google\LocalGoogleSheetsService::class, $sheets);
    $this->assertSame('Json Brand', $sheets->readSheet('Settings', true)[0]['value']);
    $this->assertNotInstanceOf(
        \App\Services\Cloudflare\LocalR2StorageService::class,
        $this->app->make(\App\Services\Cloudflare\R2StorageService::class)
    );
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `& $php83 artisan test --filter=JsonContentSourceTest`
Expected: FAIL (unknown config key / real Sheets service bound).

- [ ] **Step 3: Add the config keys and extend the binding condition**

In `config/cms.php` add `'content_source' => env('CMS_CONTENT_SOURCE', 'sheets'),` and change `local_fixture_source` to `env('CMS_CONTENT_JSON', base_path('tests/Fixtures/sheet-data.json'))`. In `AppServiceProvider::register()`, bind `LocalGoogleSheetsService` when `config('cms.local_mode') || config('cms.content_source') === 'json'`. Leave both Cloudflare bindings and the `public_url` reset keyed on `cms.local_mode` only.

- [ ] **Step 4: Run it and confirm it passes**

Run: `& $php83 artisan test --filter=JsonContentSourceTest`
Expected: PASS.

- [ ] **Step 5: Record the step in the ledger**

Append files touched and test output to `.superpowers/sdd/2026-11-21-cloudflare-front-end-cms/progress.md`.

---

### Task 2: JSON content fidelity and validation

**Files:**
- Test: `tests/Feature/JsonContentSourceTest.php` (append two tests)
- Modify: `app/Console/Commands/PublishWebsiteCommand.php` (only if `--content` is needed; do not add it unless the tests require it)

**Interfaces:**
- Consumes: `cms.content_source` and `CMS_CONTENT_JSON` from Task 1; existing `PublishingService::preview()` and `DataValidationService`.
- Produces: proof that a JSON file reproduces all 12 tabs and that invalid content cannot produce a live build.

- [ ] **Step 1: Add the fidelity test**

```php
public function test_json_file_round_trips_every_tab_identically(): void
{
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true);
    $path = $this->tempPath('content.json');
    file_put_contents($path, (string) json_encode($fixture));
    config()->set('cms.content_source', 'json');
    config()->set('cms.local_fixture_source', $path);
    config()->set('cms.local_fixture_work', $this->tempPath('content-work.json'));

    $sheets = $this->app->make(\App\Services\Google\GoogleSheetsService::class);
    foreach ($fixture as $tab => $rows) {
        $this->assertSame($rows, $sheets->readSheet($tab, true), $tab);
    }
}
```

- [ ] **Step 2: Add the invalid-content tests**

```php
public function test_invalid_content_json_blocks_publish_without_touching_live_build(): void
{
    // Seed content.json from the fixture, then set two Pages rows to the same slug.
    // Assert: PublishingService::preview() throws RuntimeException and the directory
    // at publishing.paths.current is byte-identical before and after.
}

public function test_broken_product_category_reference_blocks_publish(): void
{
    // Seed content.json from the fixture, set Products row category_id to 'missing'.
    // Assert: preview() throws RuntimeException naming the invalid reference.
}
```

- [ ] **Step 3: Run and fix only what fails**

Run: `& $php83 artisan test --filter=JsonContentSourceTest`
Expected: PASS after minimal fixes to content-source wiring; no template edits.

- [ ] **Step 4: Record in the ledger**

---

### Task 3: One-time import from the live Google Sheet

**Files:**
- Create: `app/Console/Commands/ImportSheetContentCommand.php`
- Test: `tests/Feature/ImportSheetContentCommandTest.php`

**Interfaces:**
- Consumes: `GoogleSheetsService::sheetTitles(): array` and `GoogleSheetsService::readSheet(string $tab, bool $fresh = false): array`.
- Produces: `php artisan cms:content:import {--out=storage/app/content.json}` writing the 12-tab JSON shape.

- [ ] **Step 1: Write the failing test**

```php
public function test_import_writes_all_twelve_tabs_in_the_expected_shape(): void
{
    config()->set('cms.local_mode', true);
    $out = $this->tempPath('imported.json');

    $this->artisan('cms:content:import', ['--out' => $out])->assertSuccessful();

    $data = json_decode((string) file_get_contents($out), true);
    $this->assertSame(array_keys(json_decode((string) file_get_contents(base_path('tests/Fixtures/sheet-data.json')), true)), array_keys($data));
    $this->assertSame('Knots & Perl', $data['Settings'][0]['value']);
}
```

- [ ] **Step 2: Confirm it fails, then implement the command**

The command loops `sheetTitles()`, calls `readSheet($tab, true)`, and writes pretty JSON to `--out`, creating the parent directory. It must not modify the spreadsheet.

- [ ] **Step 3: Run and confirm it passes**

Run: `& $php83 artisan test --filter=ImportSheetContentCommandTest`
Expected: PASS.

- [ ] **Step 4: Run the real import once while Google is still connected**

Run: `& $php83 artisan cms:content:import --out=storage/app/content.json`
Expected: file exists with 12 top-level keys; spot-check `Settings` and `Products`. Then upload this file to R2 as `content.json` using the operator's R2 credentials. Record the object key in the ledger. This step is manual and must happen before Task 7.

---

### Task 4: Cloudflare Worker API

**Files:**
- Create: `worker/wrangler.toml`
- Create: `worker/src/auth.js`
- Create: `worker/src/store.js`
- Create: `worker/src/index.js`
- Create: `worker/test/worker.test.js`

**Interfaces:**
- Consumes: R2 bucket binding `CONTENT` containing `content.json` and image objects under `assets/`; secrets `ADMIN_PASSWORD`, `SESSION_SECRET`, `GITHUB_TOKEN`, `GITHUB_REPOSITORY`, `GITHUB_WORKFLOW`, `STATUS_SECRET`; vars `R2_PUBLIC_URL`.
- Produces: `handle(request, env, now = Date.now())` exported from `worker/src/index.js`, plus default `fetch` export for Wrangler. Routes: `POST /api/login`, `POST /api/logout`, `GET /api/content`, `PUT /api/content`, `POST /api/image`, `POST /api/publish`, `GET /api/status`, `POST /api/status` (secret header).

- [ ] **Step 1: Write failing tests with `node:test`**

`worker/test/worker.test.js` must cover, with a fake `env` whose `CONTENT` binding is an in-memory `Map` and a stubbed global `fetch`:

```js
test('wrong password is rejected and no session cookie is issued', ...);
test('expired session cannot read or save content', ...);
test('content save and read round-trip', ...);
test('oversized and non-image uploads are rejected before R2 write', ...);
test('publish without GITHUB_TOKEN returns 503 and does not call GitHub', ...);
test('publish calls workflow_dispatch and requires a valid session', ...);
```

- [ ] **Step 2: Confirm they fail**

Run: `node --test worker/test`
Expected: FAIL (modules missing).

- [ ] **Step 3: Implement auth.js and store.js**

`auth.js`: HMAC-SHA256 session token via Web Crypto, payload `{exp}`, 8-hour expiry, HttpOnly + SameSite=Strict cookie, `verifySession(cookieHeader, env, now)` returns boolean. `store.js`: `getContent(env)`, `putContent(env, data)` with a JSON-shape guard (object with at least the 12 tab keys), `putImage(env, key, bytes, mime)` writing to `CONTENT`.

- [ ] **Step 4: Implement index.js**

`handle(request, env, now)` dispatches routes. `/api/image` accepts multipart, enforces `IMAGE_MAX_BYTES` 8 MiB and MIME in `image/jpeg,image/png,image/webp,image/gif`, derives a unique key under `assets/`, returns `{key, url}`. `/api/publish` requires auth and `GITHUB_TOKEN`, posts to `https://api.github.com/repos/${repo}/actions/workflows/${workflow}/dispatches` with `ref: main`, returns 202. `/api/status` GET is public read, POST requires `x-status-secret`.

- [ ] **Step 5: Run tests**

Run: `node --test worker/test`
Expected: PASS.

- [ ] **Step 6: Add wrangler.toml**

Name, `main = "src/index.js"`, compatibility date, `[[r2_buckets]] binding = "CONTENT"`, `[vars] R2_PUBLIC_URL`. No secrets in this file. Document the `wrangler secret put` commands in `worker/README.md`.

---

### Task 5: Browser admin app

**Files:**
- Create: `admin/schema.json`
- Create: `admin/index.html`
- Create: `admin/app.js`
- Create: `admin/styles.css`
- Create: `admin/fields.js`
- Create: `admin/test/fields.test.js`

**Interfaces:**
- Consumes: Worker endpoints from Task 4; content JSON shape from Task 1.
- Produces: `admin/schema.json` (three screens, each with `id`, `label`, `tab`, `fields`; each field has `key`, plain `label`, `type` and `column` or key-as-column; screens may set `slugFrom` and `layout: "key-value"`). In `admin/fields.js`: `loadSchema()`, `labelFor(schema, screenId, key)`, `fromContentJson(schema, screenId, content)`, `toContentJson(schema, screenId, state, content)`, `makeBlankItem(schema, screenId)`.

- [ ] **Step 1: Write `admin/schema.json` for the three screens**

Pages: fields `title` (column `title`), `text` (column `content`), `picture` (column `primary_image`), `slugFrom: "title"`. Products: `name`, `price`, `description`, `picture`, `slugFrom: "name"`. Settings: `layout: "key-value"` with fields whose `key` is the Settings row key (e.g. `site_name`). Only template-supported columns appear.

- [ ] **Step 2: Write failing tests for the schema-driven mapping**

`admin/test/fields.test.js` with `node:test`:

```js
test('schema exposes exactly Pages, Products, Settings', ...);
test('no technical word appears in any screen or field label', ...); // slug, JSON, R2, tab, token
test('renaming a label in the schema changes labelFor without code changes', ...);
test('adding a field to the schema adds it to a blank item and to saved rows', ...);
test('brand name maps to the Settings site_name row', ...);
test('adding a page generates its slug and an active Pages row', ...);
test('changing a picture stores the R2 key, not the public URL', ...);
```

- [ ] **Step 3: Confirm they fail, then implement `admin/fields.js`**

`fromContentJson` uses each field's `column` (falling back to `key`) to read row values into state; `toContentJson` writes them back using the same mapping and leaves unmapped columns untouched. `makeBlankItem` creates an item with all schema fields empty. Slugs, `status: active` and `sort_order` are generated internally from the screen's `slugFrom` and the item's list position. No per-field code.

- [ ] **Step 4: Build the static app with exactly three screens**

`app.js` fetches `/schema.json` on load and renders forms entirely from it.

- **Pages**: list of page cards; **Add a page** creates a blank card from `makeBlankItem`; clicking a card renders its schema fields, picture fields showing a thumbnail and **Change picture**.
- **Products**: same pattern; **Add a product**.
- **Settings**: renders the key-value screen without list controls.
- Every screen: one big **Publish** button (saves then requests publish), a one-line status ("Saved", "Published", or a plain-English problem), and a small **Download backup** link. No Save/Preview buttons, no separate image manager, no JSON view.
- `app.js` calls `GET/PUT /api/content`, `POST /api/image`, `POST /api/publish`, `GET /api/status`. No framework, no build step.

- [ ] **Step 5: Run tests and a browser smoke check**

Run: `node --test admin/test`. Then serve `admin/` with any static file server: log in, open Pages, change one title, change one picture, add a page, click Publish, and confirm the status line updates. Rename one label in `admin/schema.json`, reload, and confirm the form label changes with no JavaScript edit.

---

### Task 6: GitHub Actions deploy workflow

**Files:**
- Create: `.github/workflows/deploy.yml`
- Test: `tests/Unit/DeployWorkflowTest.php`

**Interfaces:**
- Consumes: R2 secrets `CF_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`; `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_PAGES_PROJECT`, `CLOUDFLARE_R2_PUBLIC_URL`; `STATUS_URL`, `STATUS_SECRET`.
- Produces: a `workflow_dispatch` workflow that downloads `content.json` from R2, runs the existing generator with `CMS_CONTENT_SOURCE=json`, and uploads the artifact to Cloudflare Pages.

- [ ] **Step 1: Write the failing test**

```php
public function test_deploy_workflow_uses_json_source_and_pages_deploy(): void
{
    $yml = (string) file_get_contents(base_path('.github/workflows/deploy.yml'));
    $this->assertStringContainsString('workflow_dispatch', $yml);
    $this->assertStringContainsString('CMS_CONTENT_SOURCE: json', $yml);
    $this->assertStringContainsString('cms:publish', $yml);
    $this->assertStringContainsString('r2.cloudflarestorage.com', $yml);
}
```

- [ ] **Step 2: Create the workflow**

Steps: checkout; `shivammathur/setup-php@v2` with PHP 8.3; `composer install --no-interaction --prefer-dist`; download `content.json` from R2 using the AWS CLI with `--endpoint-url https://${{ secrets.CF_ACCOUNT_ID }}.r2.cloudflarestorage.com`; run `php artisan cms:publish` with the env above; POST success or failure to `${{ secrets.STATUS_URL }}` with header `x-status-secret`.

- [ ] **Step 3: Run the test**

Run: `& $php83 artisan test --filter=DeployWorkflowTest`
Expected: PASS.

---

### Task 7: Remove Google, rewrite docs, final verification

**Files:**
- Modify: `routes/web.php` (remove Google + spreadsheet routes)
- Modify: `resources/views/dashboard/index.blade.php`, `resources/views/layouts/dashboard.blade.php`
- Delete: `app/Http/Controllers/GoogleAuthController.php`, `app/Http/Controllers/SpreadsheetController.php`, `app/Services/Google/GoogleAuthService.php`, `app/Services/Google/GoogleDriveService.php`, `app/Services/Google/GoogleSheetsService.php`, `app/Services/Google/LocalGoogleSheetsService.php`, `config/google.php`, Google-related Blade views
- Modify/replace: all tests and services referencing those classes (keep the file-backed content service under a new neutral name if the generator still needs it)
- Rewrite: `docs/UPDATING_CONTENT.md`; create `docs/CLOUDFLARE_SETUP.md`
- Test: full suite

**Interfaces:**
- Consumes: Tasks 1–6 complete and the one-time import in Task 3 already run.
- Produces: an app with no Google code or OAuth UI; all publishing runs through JSON + Worker + Action.

- [ ] **Step 1: Move the file-backed content service out of the Google namespace**

Rename `LocalGoogleSheetsService` to `JsonContentService` under `app/Services/CMS/`, update `AppServiceProvider` and all callers. Keep `GoogleSheetsService` only until Step 3.

- [ ] **Step 2: Remove Google routes/UI and delete Google classes**

Remove routes 26–32 in `routes/web.php`, dashboard Google/Spreadsheet panels, controllers, services and config. Update `AdminContentController`, `ImageController`, `SheetWriteService`, `PublishingService`, `SyncWebsiteCommand` and the tests from Tasks 1–3 to use `JsonContentService` instead of `GoogleSheetsService`/`LocalGoogleSheetsService`.

- [ ] **Step 3: Update or delete stale tests**

Fix the four known stale failures (`SpreadsheetControllerTest` 3 pre-auth tests, `StaticSiteGeneratorTest:46` old `mark-knots` markup) and remove Google-specific tests that no longer apply.

- [ ] **Step 4: Run full verification**

Run: `& $php83 artisan test`, `node --test worker/test`, `node --test admin/test`
Expected: all PASS.

- [ ] **Step 5: Rewrite operator docs**

`docs/UPDATING_CONTENT.md` becomes the browser-admin guide (login, edit, upload, preview, publish, export backup). `docs/CLOUDFLARE_SETUP.md` lists the one-time Worker secrets, R2 bucket, GitHub Secrets and Pages project setup — with no Google step.

- [ ] **Step 6: Record final ledger entry**
