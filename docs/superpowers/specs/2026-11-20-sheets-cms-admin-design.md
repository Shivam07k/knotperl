# Google Sheets CMS + Admin Write-Back — Design

Date: 2026-11-20
Status: draft for approval

## Intent

The owner should be able to manage the whole website from a Google Sheet **or** from a browser admin panel, without a database and without visitors ever touching Google or Cloudflare. Both paths must write to the same sheet, so neither can drift from the other. The generated site stays static and keeps working when Google is down.

This came from the full project spec plus the owner's decision to take both available starting tracks: make the pipeline provable offline, and close the functional gaps in what already exists.

## Goals

1. Google Sheets remains the single source of truth; admin edits are written back into the same 12-tab spreadsheet.
2. Validate → generate → preview can be exercised locally with fixture data and no credentials.
3. The publishing dashboard requires an operator login and exposes no secrets.
4. Settings colours/fonts from the sheet actually reach the generated site.
5. Contact and newsletter forms deliver to an owner-configured endpoint instead of only printing a placeholder.
6. Existing styling, intro, navigation and responsive behaviour stay intact.

## Non-goals

- No MySQL, PostgreSQL, SQLite, Eloquent models or migrations.
- No cart, checkout, payment, orders or customer accounts.
- No multi-tenancy or multiple Google accounts in this build.
- No database-backed sessions.
- No full admin editing of all 12 tabs in the first slice (see scope).
- No new CSS redesign of the visitor site.

## Anti-goals

- Never publish an invalid or incomplete build; the last successful live version survives failure.
- Never put tokens, keys or private config into generated JS/JSON.
- Never silently discard invalid spreadsheet rows; report them per tab and row.
- Never overwrite the current generated site with a half-written export.

## Constraints

- Laravel 12 is an **offline build engine**; no site/visitor routes exist and the generated site must not need Laravel running.
- Credentials for Google, R2 and GitHub are blank in this checkout; the workspace is not a git repo, so live OAuth, uploads and deployment cannot be exercised here.
- Local PHP is 8.2.12; `vendor/` requires PHP >= 8.3, so PHPUnit currently refuses to start. Composer binary is not installed, but `vendor/` is present.
- Public generated output lives in `site/`; source assets live in `public/`, and a cached copy in `storage/app/generated/current/`. All three CSS/JS copies must stay in sync after generation changes.
- A static visitor site cannot call Laravel, so form delivery needs an external public endpoint.

## Current state (why we start here)

| Phase | State |
|---|---|
| 1 Laravel + Google OAuth | Coded; encrypted token storage, minimal scopes, no operator login |
| 2 Sheets connection + template | Coded; 12-tab schema/template, selection and creation screens |
| 3 Schema + validation | Coded; fixture-testable, per-row errors |
| 4 R2 image manager | Coded; sha256 dedupe, variants, deferred deletion, needs credentials |
| 5 Static generator | Coded; HTML/JSON/sitemap/robots/_redirects/asset minification |
| 6 Responsive catalogue | Partial; forms are placeholders, theme settings unused |
| 7 SEO + navigation | Mostly complete; colours/fonts not applied |
| 8 Publishing dashboard | Coded but unauthenticated |
| 9 GitHub + Cloudflare Pages | Workflow coded, untestable without secrets/git |
| 10 Tests + docs | Tests exist but stale in places; no CI test job |

## Approaches considered

**A — Admin writes back into the same Google Sheet (chosen).** The panel is a second editor for the sheet, not a second CMS. Adds a write-back service and schema-driven CRUD forms, but keeps one source of truth and no database. Fits the owner's request to manage from both places.

**B — Operational dashboard only, content edited in Sheets.** Much smaller, but the owner cannot edit a product from the panel, which they explicitly rejected.

**C — Rebuild the engine from the spec.** Eight of ten phases are already implemented; rebuilding would discard working code and delay verification.

For offline proof: **fixture-backed service bindings in local mode** (chosen) rather than "tests only" (doesn't exercise the dashboard/generator together) or "connect real credentials" (impossible here).

## Design

### 1. Write-back and one source of truth

- Add `App\Services\CMS\SheetWriteService` over `GoogleSheetsService`. It uses the Values API for row updates/appends and `spreadsheets.batchUpdate` → `deleteDimension` for row deletion. `GoogleSheetsService::updateCell` stays for single-cell edits.
- Every write is validated against `schema.json` (headers, types, enums, required fields, duplicates, references) **before** it is sent, scoped to the rows being written plus their references. Full-dataset validation stays the gate for preview/publish: a pre-existing bad row in another tab must not stop the operator fixing the row in front of them, but it must still block publishing. Errors are shown per row and tab.
- Rows carry `__row` from `readAll()`. Updates include an optimistic check: re-read the target row immediately before writing and refuse if it changed since the form was loaded. This narrows (does not eliminate) the window where a simultaneous hand-edit in Sheets could be overwritten.
- After any write: clear the sheet cache, mark the current preview stale, and append a line to `storage/app/publishing-logs/admin-actions.log` with timestamp, tab, record ID and action. No row contents are logged.
- Admin CRUD is generated from the schema headers/types/enums for these tabs: **Settings, Pages, Products, Sections, Navigation, Categories**. (Categories is included although the first proposal listed five: a product form needs a real category to reference, not a raw ID typed by hand.) The other six tabs stay visible/read-only in the panel and remain editable in Sheets.
- Settings is a keyed form, not a raw grid: controls are chosen from the Settings sheet's own `type` column (`text`, `color`, `font`, `bool`, `number`, plus new `url`). The two form-endpoint keys are added to `schema.json` defaults, `template.json` and the fixture with type `url`.
- On create, IDs are generated automatically from the existing prefixes (`p`, `pr`, `c`, `s`, `n`) with the next free suffix; `created_at` and `updated_at` are set by the server. On edit, only `updated_at` changes. The operator never types a primary key.
- Image columns are plain, validated key/URL text inputs (R2 domain only) with a "browse R2" picker backed by the existing image-manager search. `gallery_images` is a repeatable list joined with `|`, matching the current sheet format.
- Sanitisation is unchanged: spreadsheet values are escaped on render, and `Custom HTML` sections keep the existing sanitizer.

### 2. Local fixture mode

- Add `config/cms.php` with `local_mode` from `CMS_LOCAL_MODE`.
- When enabled, `AppServiceProvider` binds `GoogleSheetsService`, `R2StorageService` and `PagesDeploymentService` to local implementations: sheets read a persistent working copy at `storage/app/generated/local-fixture/sheet-data.json`, seeded once from `tests/Fixtures/sheet-data.json`, with a dashboard "Reset fixture" button. Edits therefore survive across requests. R2 paths resolve to a local folder and deployment is a no-op. No network calls.
- Google connect/select/create screens are hidden in this mode and their routes return a "not available in local mode" notice, so no real OAuth call is attempted.
- The dashboard shows a persistent "LOCAL FIXTURE MODE — nothing is live" banner. Preview works; real publish/rollback is refused in this mode.
- This makes `cms:validate`, `cms:generate`, `cms:preview` and the dashboard workflow runnable here without credentials.

### 3. Operator login

- `ADMIN_PASSWORD_HASH` env (bcrypt hash, no default). If unset, the dashboard refuses to load with a setup message.
- Login form posts to a session login route; use `password_verify` and the **file** session driver (no database). Logout route included.
- `admin.auth` middleware protects every dashboard, Google, spreadsheet, image and publishing route except `/webhook/build`, which keeps its existing shared-secret verification.
- Login route is throttled. No credentials are ever rendered into a view.

### 4. Settings → generated site

- Add `App\Services\CMS\ThemeService`. `site.css` defines real variables (`--bg`, `--bg-soft`, `--surface`, `--text`, `--muted`, `--accent`, `--line`, `--serif`, `--sans`), so `theme.css` must set those names, not invented ones.
- Only `accent_color` → `--accent` and `default_font` → `--serif` are applied. They are emitted as `html[data-theme="obsidian"]` and `html[data-theme="pearl"]` blocks loaded after `site.css`, so the visitor's theme toggle keeps working. `primary_color` and `secondary_color` remain stored sheet data only: applying them would repaint the finished design and is out of scope.
- Colours are validated as `#rgb`/`#rrggbb` and fonts against an allowlist (Playfair Display, Montserrat, Georgia, system-ui). Invalid values fall back to defaults and produce a validation warning; no arbitrary CSS is emitted.
- No visitor JS changes and no styling redesign.

### 5. Form delivery

- Add Settings keys `form_endpoint` and `newsletter_endpoint` (https URLs; http allowed for localhost only). Both are seeded in `schema.json`, `template.json` and the fixture.
- Payload contract: `POST` JSON `{ "type": "contact" | "newsletter", "fields": { ...named form fields... }, "page": "<slug>", "submitted_at": "<ISO 8601>" }`. The newsletter input gets a `name` attribute. `site.js` shows the existing success state on 2xx and a clear error otherwise.
- The generated `_headers` currently restricts `connect-src` to `'self'`; the generator adds only the validated endpoint origins to that directive so the fetch is not blocked. No wildcard CSP is introduced.
- When unset, today's placeholder message stays. Endpoints are owner-provided public form collectors; no secret is placed in generated JavaScript. This is a builder call forced by static hosting: those visitors have no Laravel server to post to.

### 6. Publishing safety

- Keep the existing preview → publish → rollback flow and atomic temp-dir generation.
- Admin edits only change the draft; publishing still requires an explicit action. Validation failure preserves the live version.
- `cms:export` copies the successful generated build into the committed `site/` directory (including the new `theme.css`) and is the trigger for the Pages deploy; local-mode previews never touch `site/`. In this checkout, without PHP 8.3, asset sync of `public/` → `site/` → `storage/app/generated/current/` stays manual.
- Automatic change detection and approval-based publishing from the spec are **deferred**; manual publish and the signed webhook remain.

### 7. Tests and verification

- New/updated tests: write-back service (mocked Sheets, incl. optimistic-conflict and validation-failure cases), local mode bindings, auth middleware, theme CSS output, form endpoint rendering, and corrected `StaticSiteGeneratorTest` assertions to match current templates.
- Run with PHP 8.3. If 8.3 cannot be installed here, syntactically check changed PHP with the local 8.2 binary, report the blocked suite plainly, and do not claim tests passed.

## Build order

1. Local fixture mode + operator login (makes everything else verifiable).
2. `SheetWriteService` + schema-driven CRUD for the six editable tabs.
3. Theme settings + form endpoints.
4. Test repair, docs updates, sync generated assets.

## Builder's calls (check these while reading)

- Six editable tabs, not five (Categories added for product references).
- Scoped validation on write; full validation only at preview/publish, so problems can be fixed incrementally.
- External public form endpoint with a defined JSON payload; CSP origins added per endpoint.
- File sessions; no session database. Settings is a typed keyed form; IDs and timestamps are server-generated; image fields are validated text with an optional R2 picker.
- Only `accent_color` and `default_font` are applied, via real CSS variables under both themes; primary/secondary colours stay as sheet data to protect the finished styling.
- Local fixture is a persistent resettable file; Google connection screens are hidden there.
- Local mode refuses real publishing; `cms:export` is what refreshes `site/` for deployment.
- Approval-mode publishing and the remaining six editable tabs deferred.

## Risks

- Concurrent edits in Sheets and the panel can still race; the optimistic check narrows but does not remove this. A full Apps Script revision-token scheme is out of scope.
- PHP 8.3 is missing locally, so the test suite may remain unrun in this checkout.
- Live Google/R2/GitHub paths cannot be verified without credentials and a git repo; those remain code-complete but unproven here.
