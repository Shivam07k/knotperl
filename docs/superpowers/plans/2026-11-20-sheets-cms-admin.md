# Sheets CMS Admin Write-Back Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the Google Sheet editable from both Sheets and a logged-in browser panel, and make the whole validate → generate → preview pipeline provable offline with no credentials.

**Architecture:** Laravel stays an offline build engine. A local-mode service binding swaps Sheets/R2/deployment for file-backed implementations, so the dashboard and commands run with fixture data. A `SheetWriteService` writes schema-validated rows back through the Sheets API (same spreadsheet, no database), and a schema-driven CRUD UI calls it. Theme settings and form endpoints are then threaded into the static generator without changing the existing visual design.

**Tech Stack:** Laravel 12, PHP 8.3+, Google Sheets API v4, Blade, PHPUnit/Mockery, file sessions, Cloudflare R2/Pages (untestable locally).

**Spec:** `docs/superpowers/specs/2026-11-20-sheets-cms-admin-design.md`

## Global Constraints

- Google Sheets is the single source of truth. Admin edits write into the same 12-tab spreadsheet. No MySQL, PostgreSQL, SQLite, Eloquent models or migrations.
- Static output must not call Google, R2 or Laravel at visitor time; no credentials or tokens in generated JS/JSON.
- Never publish an invalid or incomplete build; the last successful live version survives failure.
- Do not change the visitor design: no CSS redesign, no intro timing changes, no nav z-index changes, no carousel behaviour changes. Responsive output stays overflow-free from 1920px to 320px.
- Generated output lives in `site/`; source assets in `public/`; cached build in `storage/app/generated/current/`. Keep CSS/JS byte-identical across all three.
- Local mode refuses real publish and rollback. Preview is allowed. Orders: local mode → login → write-back → CRUD → theme/forms → tests/docs.
- Local PHP is 8.2.12 but `vendor/` requires PHP >= 8.3. PHPUnit cannot start here. Use `C:\xampp\php\php.exe -l` for syntax and report the suite as blocked, not passed.
- This is not a git repo. Do **not** run `git commit`. Each task ends by ticking its checkbox and listing changed files.

## Review Focus

- A stale admin form must not overwrite a row someone edited in Sheets between load and save → `SheetWriteServiceTest::test_stale_checksum_blocks_update` (Task 3).
- One bad row in an unrelated tab must not block fixing the row in front of the operator, but must still block publishing → `SheetWriteServiceTest::test_scoped_validation_ignores_other_tabs` (Task 3) and `PublishingServiceTest::test_preview_still_rejects_full_dataset_errors` (Task 5).
- Unset or invalid form endpoints must leave the existing placeholder form working and emit no CSP wildcard → `StaticSiteGeneratorTest::test_no_endpoints_keeps_placeholder_and_self_only_csp` (Task 5).
- A sheet font/colour change must not kill the visitor theme toggle; both theme blocks must remain → `ThemeServiceTest::test_css_overrides_both_themes_and_validates_values` (Task 5).
- Anonymous users must not reach publishing/content routes, while the signed webhook stays reachable → `AdminAuthTest::test_protected_routes_redirect_and_webhook_does_not` (Task 2).

---

## File Structure

Create:
- `config/cms.php` — local mode, fixture paths, editable tab allowlist, admin password hash.
- `app/Services/Google/LocalGoogleSheetsService.php` — file-backed Sheets surface.
- `app/Services/Cloudflare/LocalR2StorageService.php`, `app/Services/Cloudflare/LocalPagesDeploymentService.php` — no-network stand-ins.
- `app/Http/Middleware/AdminAuth.php`, `app/Http/Controllers/AdminAuthController.php`, `resources/views/auth/login.blade.php`.
- `app/Services/CMS/SheetWriteService.php`, `app/Http/Controllers/AdminContentController.php`, `resources/views/admin/content/index.blade.php`, `resources/views/admin/content/form.blade.php`.
- `app/Services/CMS/ThemeService.php`, `public/css/theme.css` (generated output copy only).
- Tests: `tests/Feature/LocalModeTest.php`, `tests/Feature/AdminAuthTest.php`, `tests/Unit/SheetWriteServiceTest.php`, `tests/Unit/ThemeServiceTest.php`.

Modify:
- `bootstrap/app.php` (middleware alias), `routes/web.php`, `app/Providers/AppServiceProvider.php`, `config/google.php` (write scope), `config/publishing.php` (local guard).
- `app/Services/Google/GoogleSheetsService.php` (`appendRow`, `updateRow`, `deleteRow`).
- `app/Services/CMS/DataValidationService.php` (`validateScope`, endpoint validation).
- `app/Services/CMS/StaticSiteGenerator.php` (`theme.css`, CSP origins, `headers()` signature).
- `resources/views/site/layouts/app.blade.php`, `resources/views/site/pages/contact.blade.php`, `resources/views/site/partials/sections.blade.php`, `public/js/site.js`.
- `resources/cms/schema.json`, `resources/cms/template.json`, `tests/Fixtures/sheet-data.json`, `.env.example`, dashboard layout/index, selected docs.

---

### Task 1: Local fixture mode

**Files:**
- Create: `config/cms.php`, `app/Services/Google/LocalGoogleSheetsService.php`, `app/Services/Cloudflare/LocalR2StorageService.php`, `app/Services/Cloudflare/LocalPagesDeploymentService.php`, `tests/Feature/LocalModeTest.php`
- Modify: `app/Providers/AppServiceProvider.php`, `config/publishing.php`, `app/Services/CMS/PublishingService.php`, `resources/views/dashboard/index.blade.php`, `resources/views/layouts/dashboard.blade.php`, `routes/web.php`

**Interfaces:**
- Consumes: `GoogleSheetsService::readAll/readSheet/headers/updateCell/clearCache` and `R2StorageService::upload/list/delete/test` (existing public methods).
- Produces: `cms.local_mode` (bool), `cms.local_fixture_source`, `cms.local_fixture_work`, `cms.local_r2_path`; classes `LocalGoogleSheetsService`, `LocalR2StorageService`, `LocalPagesDeploymentService`; route name `local.reset`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/LocalModeTest.php`:

```php
public function test_local_mode_binds_file_backed_services_and_persists_writes(): void
{
    config()->set('cms.local_mode', true);
    (new \Illuminate\Filesystem\Filesystem())->deleteDirectory(dirname((string) config('cms.local_fixture_work')));

    $sheets = $this->app->make(\App\Services\Google\GoogleSheetsService::class);
    $this->assertInstanceOf(\App\Services\Google\LocalGoogleSheetsService::class, $sheets);
    $this->assertContains('Products', $sheets->sheetTitles());

    $sheets->updateCell('Settings', 'B2', 'Renamed Local');
    $this->assertSame('Renamed Local', $sheets->readSheet('Settings', true)[0]['value']);

    $this->withSession(['admin_authenticated' => true])->post(route('local.reset'))->assertRedirect();
    $this->assertSame('Knots & Perl', $sheets->readSheet('Settings', true)[0]['value']);
}

public function test_local_mode_allows_preview_but_refuses_real_publish_and_rollback(): void
{
    config()->set('cms.local_mode', true);
    config()->set('cloudflare.r2.public_url', '');
    $publishing = $this->app->make(\App\Services\CMS\PublishingService::class);
    $this->assertNotEmpty($publishing->preview()['id']);
    $this->expectException(\RuntimeException::class);
    $publishing->publish(true);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --filter LocalModeTest`
Expected: FAIL — config/class/route not defined. If PHPUnit refuses to start on PHP 8.2, record that and proceed to the syntax check in Step 5.

- [ ] **Step 3: Add config, local services and bindings**

`config/cms.php`: `local_mode` from `CMS_LOCAL_MODE`; `local_fixture_source` = `base_path('tests/Fixtures/sheet-data.json')`; `local_fixture_work` = `storage_path('app/generated/local-fixture/sheet-data.json')`; `local_r2_path` = `storage_path('app/generated/local-fixture/r2')`; `editable_tabs` = `['Settings','Pages','Products','Sections','Navigation','Categories']`; `admin_password_hash` from `ADMIN_PASSWORD_HASH`.

`LocalGoogleSheetsService` extends `GoogleSheetsService`, declares no-arg `__construct()` (do not call parent), and overrides every public method that touches parent private state: `spreadsheetId()`, `meta()`, `sheetTitles()`, `readAll(?array $tabs = null, bool $fresh = false)`, `readSheet(string $tab, bool $fresh = false)`, `headers(string $tab)`, `updateCell(string $tab, string $a1, mixed $value)`, `clearCache()`. It reads/writes the working JSON file on every call; `headers()` comes from `SpreadsheetSchemaService::expectedHeaders()`. Seed the working file from `local_fixture_source` only when missing. Also implement `appendRow/updateRow/deleteRow` here against the JSON file (same signatures as Task 3); every inherited method that reaches parent private state must be shadowed.

`LocalR2StorageService` overrides the instance methods `upload/putJson/getJson/exists/delete/list/metadata/test` against `local_r2_path`. `LocalPagesDeploymentService::deploy(string $zipPath): array` returns `['mode'=>'local','status'=>'skipped']` and `deployment(string $id): ?array` returns `null`.

In `AppServiceProvider::register()`, when `config('cms.local_mode')` is true: bind all three concrete classes to the local classes with `singleton()`, and set `config(['cloudflare.r2.public_url' => ''])` so the existing static `R2StorageService::url()` emits root-relative paths. In `boot()` retain both Blade directives unchanged.

In `PublishingService::publish(bool $deploy = true)`, throw `RuntimeException('Real publishing is disabled in local fixture mode.')` when `$deploy && config('cms.local_mode')`. In `rollback()`, throw the same style of exception whenever local mode is on. Leave `preview()` untouched.

- [ ] **Step 4: Add banner, reset route and controller method**

`POST /local/reset` → `DashboardController::resetFixture()` deletes the working fixture file and redirects back with a success message. Add to `layouts/dashboard.blade.php` a persistent `@if(config('cms.local_mode'))` banner reading `LOCAL FIXTURE MODE — nothing is live`, plus a reset form. Add a "Reset fixture" button to `dashboard/index.blade.php` inside that same condition. Hide the Google Connect / Select spreadsheet links and controls in `dashboard/index.blade.php` and in `dashboard.blade.php` when local mode is on; the `google.*` and `spreadsheets.*` routes return a `not available in local mode` redirect in that mode so no OAuth call is attempted.

- [ ] **Step 5: Run test/verification**

Run: `C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --filter LocalModeTest`
Expected: PASS. Also run `C:\xampp\php\php.exe -l` on every new/modified PHP file; expected no syntax errors.

- [ ] **Step 6: Mark complete**

Files changed: list them here and tick the task checkbox.

---

### Task 2: Operator login and route protection

**Files:**
- Create: `app/Http/Middleware/AdminAuth.php`, `app/Http/Controllers/AdminAuthController.php`, `resources/views/auth/login.blade.php`, `tests/Feature/AdminAuthTest.php`
- Modify: `bootstrap/app.php`, `routes/web.php`, `resources/views/layouts/dashboard.blade.php`

**Interfaces:**
- Consumes: `cms.admin_password_hash` from Task 1.
- Produces: middleware alias `admin.auth`; route names `login`, `login.attempt`, `logout`.

- [ ] **Step 1: Write the failing test**

`tests/Feature/AdminAuthTest.php`:

```php
public function test_protected_routes_redirect_and_webhook_does_not(): void
{
    $this->get(route('dashboard'))->assertRedirect(route('login'));
    $this->get(route('images.index'))->assertRedirect(route('login'));
    $this->post(route('webhook.build'), [], ['HTTP_X_CMS_SIGNATURE' => 'bad'])
        ->assertStatus(401);
}

public function test_login_logout_and_missing_password_hash(): void
{
    $hash = password_hash('secret', PASSWORD_DEFAULT);
    config()->set('cms.admin_password_hash', $hash);

    $this->post(route('login.attempt'), ['password' => 'wrong'])
        ->assertSessionHasErrors('password');
    $this->post(route('login.attempt'), ['password' => 'secret'])
        ->assertRedirect(route('dashboard'));
    $this->get(route('dashboard'))->assertOk();
    $this->post(route('logout'))->assertRedirect(route('login'));
    $this->get(route('dashboard'))->assertRedirect(route('login'));

    config()->set('cms.admin_password_hash', null);
    $this->get(route('login'))->assertSee('ADMIN_PASSWORD_HASH');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --filter AdminAuthTest`
Expected: FAIL — no login routes or middleware.

- [ ] **Step 3: Implement middleware, controller and views**

`AdminAuth::handle(Request $request, Closure $next)`: if `session('admin_authenticated') === true`, continue; otherwise redirect to `route('login')`. Do not block the login routes themselves (they stay outside the group).

`AdminAuthController`: `showLogin()` renders `auth.login`; if the hash is unset, pass `setup => true` so the view prints `Set ADMIN_PASSWORD_HASH in .env to enable the dashboard`. `login(Request)` validates `password` required|string, checks `password_verify((string) $data['password'], (string) config('cms.admin_password_hash'))`, regenerates the session id on success, and returns back with `errors.password` on failure. `logout(Request)` invalidates the session.

`bootstrap/app.php`: register `$middleware->alias(['admin.auth' => \App\Http\Middleware\AdminAuth::class]);` inside the existing `withMiddleware` callback. Keep the `webhook/build` CSRF exception.

`routes/web.php`: add `GET /login` (`login`), `POST /login` (`login.attempt`, `->middleware('throttle:5,1')`), `POST /logout` (`logout`). Wrap every existing route **except** `webhook/build` in `Route::middleware('admin.auth')->group(function () { ... });`. Keep `webhook/build` registered outside the group with its existing controller action and name.

Add a logout form to `layouts/dashboard.blade.php`.

- [ ] **Step 4: Run test/verification**

Run: `C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit --filter AdminAuthTest`
Expected: PASS. Syntax-check changed PHP files with `php -l`.

- [ ] **Step 5: Mark complete**

Files changed: list them here and tick the task checkbox.

---

### Task 3: Scoped validation and sheet write-back

**Files:**
- Modify: `app/Services/Google/GoogleSheetsService.php`, `app/Services/CMS/DataValidationService.php`
- Create: `app/Services/CMS/SheetWriteService.php`, `tests/Unit/SheetWriteServiceTest.php`

**Interfaces:**
- Consumes: `GoogleSheetsService::readSheet/clearCache/updateCell`; `DataValidationService::validate`; `SpreadsheetSchemaService::tab/expectedHeaders` (all existing).
- Produces:
  - `GoogleSheetsService::appendRow(string $tab, array $values): int`
  - `GoogleSheetsService::updateRow(string $tab, int $row, array $values): void`
  - `GoogleSheetsService::deleteRow(string $tab, int $row): void`
  - `DataValidationService::validateScope(array $data, array $scope): array` where scope is `['Products' => [2, 5]]`
  - `SheetWriteService::__construct(GoogleSheetsService $sheets, DataValidationService $validator, SpreadsheetSchemaService $schema)`
  - `SheetWriteService::checksum(array $row, array $headers): string`
  - `SheetWriteService::nextId(string $tab): string`
  - `SheetWriteService::save(string $tab, array $input): array` → `['ok'=>bool,'errors'=>array,'row'=>int]`
  - `SheetWriteService::delete(string $tab, int $row, string $checksum): array`

- [ ] **Step 1: Write the failing test**

`tests/Unit/SheetWriteServiceTest.php` — use a Mockery mock of `GoogleSheetsService` injected into `SheetWriteService` with real `DataValidationService` + `SpreadsheetSchemaService` and `$this->sheetData()` as backing data:

```php
public function test_create_assigns_id_and_timestamp_and_writes_one_row(): void
public function test_scoped_validation_ignores_other_tabs_but_full_validation_does_not(): void
public function test_stale_checksum_blocks_update(): void
public function test_update_uses_expected_row_and_delete_removes_it(): void
```

Assertions: new product gets `product_id === 'pr004'` and a server-set `updated_at`; an invalid `regular_price` in the submitted row returns `ok=false` and no write call; an unrelated pre-existing `Products` error is excluded from `validateScope()` but present in `validate()`; a changed checksum returns an error named `conflict` with no `updateRow` call; an accepted update calls `updateRow('Products', 2, ...)` and `delete('Products', 3, $checksum)` calls `deleteRow`.

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\phpunit\phpunit --filter SheetWriteServiceTest`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Add the three write primitives to `GoogleSheetsService`**

`appendRow` calls `spreadsheets_values->append($id, "'Tab'!A1", new ValueRange(['values'=>[$values]]), ['valueInputOption'=>'RAW','insertDataOption'=>'INSERT_ROWS'])` and returns the row number parsed from `updates.updatedRange`. `updateRow` calls `spreadsheets_values->update` on `'Tab'!A{row}:ZZ{row}` with one value row. `deleteRow` resolves the sheet's numeric id via `spreadsheets->get($id, ['fields'=>'sheets.properties.title,sheets.properties.sheetId'])` and posts a `batchUpdate` with a `deleteDimension` request for `ROWS` start `$row-1`, end `$row`. Each method calls `clearCache()` after a successful write. Use the same quote-escaping helper pattern already used by `updateCell`.

- [ ] **Step 4: Add `validateScope()` and endpoint validation**

In `DataValidationService`, add `validateScope(array $data, array $scope): array`: run `$this->validate($data)`, keep only errors whose `sheet` is a key of `$scope` and whose `row` is in that tab's list. Return `['ok' => $errors === [], 'errors' => $errors, 'warnings' => []]`. Do not change `validate()`'s return shape.

Also in `validate()`, for `Settings` rows whose `key` is `form_endpoint` or `newsletter_endpoint` and whose `value` is non-empty, add an error when the value is not a valid URL, or when its scheme is not `https` and its host is not `localhost`/`127.0.0.1`.

- [ ] **Step 5: Implement `SheetWriteService`**

`checksum()` hashes the header-ordered values with `hash('sha256', implode("\x1f", ...))`. `nextId()` maps `Pages=>p, Products=>pr, Categories=>c, Sections=>s, Navigation=>n`, scans existing IDs for `^prefix(\d+)$`, and returns `sprintf('%s%03d', $prefix, max+1)`.

`save()`: read the tab fresh; locate the row by `$input['__row']`; when updating, compare `checksum($row, $headers)` to `$input['__checksum']` and return `['ok'=>false,'errors'=>[['message'=>'conflict: row changed since it was loaded']],'row'=>$row]` on mismatch. Build the candidate row in schema header order, generating ID/timestamps server-side on create and setting only `updated_at` on update. Merge the candidate into the full dataset and call `validateScope()` with the candidate's row number. If it fails, return the errors without writing. Otherwise call `updateRow` or `appendRow` and return `['ok'=>true,'errors'=>[],'row'=>$row]`.

`delete()`: re-check the checksum, then call `deleteRow` and return the same shape.

On every successful save/delete, append one JSON line to `storage/app/publishing-logs/admin-actions.log` with `at`, `tab`, `action`, and record ID only — never row contents. A failed write logs nothing.

- [ ] **Step 6: Run test/verification**

Run: `C:\xampp\php\phpunit\phpunit --filter SheetWriteServiceTest`
Expected: PASS. Syntax-check changed PHP files.

- [ ] **Step 7: Mark complete**

Files changed: list them here and tick the task checkbox.

---

### Task 4: Schema-driven admin content editor

**Files:**
- Create: `app/Http/Controllers/AdminContentController.php`, `resources/views/admin/content/index.blade.php`, `resources/views/admin/content/form.blade.php`
- Modify: `routes/web.php`, `app/Http/Controllers/ImageController.php`, `resources/views/layouts/dashboard.blade.php`, `resources/views/dashboard/index.blade.php`

**Interfaces:**
- Consumes: `cms.editable_tabs`; `SheetWriteService::checksum/nextId/save/delete`; `ImageManagerService::search($prefix, $q)` (existing).
- Produces: route names `content.index`, `content.create`, `content.store`, `content.edit`, `content.update`, `content.destroy`, `images.keys`.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/AdminAuthTest.php`:

```php
public function test_only_editable_tabs_are_reachable_by_authenticated_operators(): void
{
    config()->set('cms.admin_password_hash', password_hash('secret', PASSWORD_DEFAULT));
    $this->withSession(['admin_authenticated' => true])
        ->get('/content/Products')->assertOk();
    $this->withSession(['admin_authenticated' => true])
        ->get('/content/SEO')->assertNotFound();
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `C:\xampp\php\phpunit\phpunit --filter AdminAuthTest`
Expected: FAIL — no content routes.

- [ ] **Step 3: Add the controller and routes**

`AdminContentController` methods: `index(string $tab)`, `create(string $tab)`, `store(Request $request, string $tab)`, `edit(string $tab, int $row)`, `update(Request $request, string $tab, int $row)`, `destroy(string $tab, int $row)`. A private `tabOrFail(string $tab): array` looks up `SpreadsheetSchemaService::tab()` and aborts 404 unless `$tab` is in `cms.editable_tabs`. `index()` passes rows read via `GoogleSheetsService::readSheet($tab)` plus the schema definition. `create()`/`edit()` pass field metadata (header, type from schema `types`/`enums`/`images`, enum options from `SpreadsheetSchemaService::enums()`), the row values and `checksum()` for update forms. `store()`/`update()` pass all posted schema headers to `SheetWriteService::save()`, redirect back with the returned errors on failure and with a success message on write. `destroy()` calls `SheetWriteService::delete()` with the posted checksum.

Routes use `->where('tab', 'Settings|Pages|Products|Sections|Navigation|Categories')` and stay inside the `admin.auth` group. Add these links to the dashboard sidebar.

- [ ] **Step 4: Build the two Blade views and the image key picker**

`index.blade.php`: one table per tab with row number, primary key, status, and Edit/Delete; for `Settings` show key/value/type/description only, with Edit linking to a keyed form. Never render a token or credential.

`form.blade.php`: one control per schema header. Control type comes from the Settings row's own `type` value; for other tabs it is inferred from schema `types`, then `enums`, then `images`. Mapping: `bool` → select true/false, `int`/`float` → number, `color` → colour text input with a regex hint, `font` → select from `['Playfair Display','Montserrat','Georgia','system-ui']`, `url` → url, `enums` → select from resolved options, `images` → text input plus a `data-image-picker` button, everything else → text/textarea. Include hidden `__row` and `__checksum` on edit forms. `gallery_images` keeps the `|`-joined string format. Primary keys, `created_at` and `updated_at` are read-only.

`GET /images/keys?q=` → `ImageController::keys(Request)` returns `response()->json(['keys' => array_column($this->images->search('', $q), 'key')])` after validating `q` as `string|max:120`. Add ~20 lines of vanilla JS in `form.blade.php` that fetches this endpoint on picker click and inserts the clicked key into the field.

- [ ] **Step 5: Run test/verification**

Run: `C:\xampp\php\phpunit\phpunit --filter AdminAuthTest`
Expected: PASS. Syntax-check changed PHP files. Open `/content/Products` in local mode and confirm a product row loads with a checksum.

- [ ] **Step 6: Mark complete**

Files changed: list them here and tick the task checkbox.

---

### Task 5: Theme settings and working form endpoints

**Files:**
- Create: `app/Services/CMS/ThemeService.php`, `tests/Unit/ThemeServiceTest.php`
- Modify: `app/Services/CMS/StaticSiteGenerator.php`, `resources/views/site/layouts/app.blade.php`, `resources/views/site/pages/contact.blade.php`, `resources/views/site/partials/sections.blade.php`, `public/js/site.js`, `resources/cms/schema.json`, `resources/cms/template.json`, `tests/Fixtures/sheet-data.json`

**Interfaces:**
- Consumes: `$site['settings']['accent_color'|'default_font'|'form_endpoint'|'newsletter_endpoint']` from `ContentBuilderService::build()` (existing).
- Produces: `ThemeService::css(array $settings): string`; generated `/css/theme.css`; body attributes `data-contact-endpoint` and `data-newsletter-endpoint`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/ThemeServiceTest.php`:

```php
public function test_css_overrides_both_themes_and_validates_values(): void
public function test_invalid_colour_and_font_fall_back_without_emitting_arbitrary_css(): void
```

Assertions: valid `#d4af37` + `Playfair Display` emits both `html[data-theme="obsidian"]` and `html[data-theme="pearl"]` blocks containing `--accent: #d4af37` and a Playfair font stack; a value like `red; background:url(x)` and an unknown font fall back to `#d4af37` and Playfair and contain no `url(`.

Add to `tests/Feature/StaticSiteGeneratorTest.php`:

```php
public function test_theme_css_and_endpoint_origins_are_generated(): void
public function test_no_endpoints_keeps_placeholder_and_self_only_csp(): void
```

Assertions: with `form_endpoint = https://forms.example.com/contact` and `newsletter_endpoint = https://forms.example.com/sub`, `css/theme.css` exists, `index.html` links it after `/css/site.css`, contact page has `data-contact-endpoint`, `_headers` contains `connect-src 'self' https://forms.example.com` exactly once and no `*`; with both endpoints empty, contact HTML still contains `Connect a form provider to receive submissions.` and `connect-src 'self'` with no extra origin. Also assert the full-dataset validation error still makes `PublishingService::preview()` throw even when `validateScope()` would ignore it.

- [ ] **Step 2: Run tests to verify they fail**

Run: `C:\xampp\php\phpunit\phpunit --filter 'ThemeServiceTest|StaticSiteGeneratorTest'`
Expected: FAIL — class/assertions missing.

- [ ] **Step 3: Add schema/template/fixture keys**

`schema.json` `defaults`: add `"form_endpoint": ""`, `"newsletter_endpoint": ""`. `template.json` Settings rows: add two rows with `type` = `url`, empty value, descriptions `Contact form collector (https)` and `Newsletter collector (https)`. `tests/Fixtures/sheet-data.json` Settings: add matching rows with the next `__row` values and empty values.

- [ ] **Step 4: Implement `ThemeService` and wire the generator**

`ThemeService::css(array $settings): string`. Accept colours only as `#rgb`/`#rrggbb`; font allowlist `['Playfair Display','Montserrat','Georgia','system-ui']` mapped to explicit CSS font stacks. Emit only two `html[data-theme=...]` blocks setting `--accent` and `--serif`. Never emit primary/secondary colours.

`StaticSiteGenerator`: change constructor to `__construct(private ContentBuilderService $builder, ?ThemeService $theme = null)` and resolve `$theme ?? new ThemeService()` so existing one-argument test instantiation still works. After `copyAssets($target)`, write `$target.'/css/theme.css'`. Change `headers()` to `headers(array $site)`: start from the current string, collect `parse_url` origins of the two validated endpoint settings, and replace `connect-src 'self'` with `connect-src 'self' <origin1> <origin2>` (https origins only, or `http://localhost`/`http://127.0.0.1` with an explicit port). Update the `render()` call site.

- [ ] **Step 5: Wire layout, forms and `site.js`**

In `layouts/app.blade.php` add `<link rel="stylesheet" href="/css/theme.css">` immediately after `/css/site.css`, and on `<body>` add `data-contact-endpoint="{{ $site['settings']['form_endpoint'] ?? '' }}"` and `data-newsletter-endpoint="{{ $site['settings']['newsletter_endpoint'] ?? '' }}"`. Do not add endpoints to `data/site.json`.

In `contact.blade.php` replace the fixed `<small>Connect a form provider…</small>` with `@if(empty($site['settings']['form_endpoint']))` around it. In `sections.blade.php` add `name="email"` to the newsletter input.

In `public/js/site.js` replace the placeholder submit handler: read the two body datasets; when a matching endpoint exists, `fetch(endpoint, {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({type, fields:Object.fromEntries(new FormData(form)), page: location.pathname, submitted_at: new Date().toISOString()})})`, show the existing success wording on 2xx and `Could not send — please try again.` otherwise; when no endpoint exists, keep the current placeholder behaviour exactly.

- [ ] **Step 6: Run tests/verification**

Run: `C:\xampp\php\phpunit\phpunit --filter 'ThemeServiceTest|StaticSiteGeneratorTest'`
Expected: PASS. Syntax-check changed PHP files. Confirm `public/css/site.css`, `public/js/intro.js` and the generated site still match the pre-change bytes apart from `theme.css` and the new form handler.

- [ ] **Step 7: Mark complete**

Files changed: list them here and tick the task checkbox.

---

### Task 6: Docs, environment and generated-asset sync

**Files:**
- Modify: `.env.example`, `config/google.php`, `docs/LOCAL_DEVELOPMENT.md`, `docs/UPDATING_CONTENT.md`, `docs/ENVIRONMENT_CONFIG.md`, `docs/DOCS_INDEX.md`, `docs/SPREADSHEET_TEMPLATE.md`
- Copy: `public/css/site.css`, `public/js/site.js` → `site/` and `storage/app/generated/current/`; generated `css/theme.css` → same two dirs.

**Interfaces:**
- Consumes: env names from Tasks 1–2 and settings keys from Task 5.
- Produces: documented `.env` contract; synced static assets.

- [ ] **Step 1: Update `.env.example` and Google scopes**

Add `CMS_LOCAL_MODE=false`, `ADMIN_PASSWORD_HASH=` (comment: bcrypt hash generated with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"`). Add `\Google\Service\Sheets::SPREADSHEETS` to `config/google.php` scopes alongside `SPREADSHEETS_READONLY` so write-back works; note in docs that existing connected accounts must reconnect once to grant the new scope.

- [ ] **Step 2: Update docs**

`LOCAL_DEVELOPMENT.md`: local mode setup, reset button, preview-only restriction. `UPDATING_CONTENT.md`: admin panel editing for the six tabs, scoped validation, stale-form conflicts, publish still required. `ENVIRONMENT_CONFIG.md`: the two new vars and the scope change. `SPREADSHEET_TEMPLATE.md`: `form_endpoint`/`newsletter_endpoint` settings. `DOCS_INDEX.md`: links to the new sections.

- [ ] **Step 3: Sync generated assets and verify**

Copy the changed CSS/JS and generated `theme.css` into `site/` and `storage/app/generated/current/` preserving byte identity. Verify with PowerShell `Get-FileHash` across the three locations for each file. Run `C:\xampp\php\php.exe -l` on all changed PHP. Run the full suite command `C:\xampp\php\php.exe vendor\phpunit\phpunit\phpunit`; if it still fails on the PHP 8.3 platform check, record that exact message and do not claim tests passed.

- [ ] **Step 4: Mark complete**

Files changed: list them here and tick the task checkbox.
