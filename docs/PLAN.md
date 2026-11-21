# Knots & Perl — Google Sheets–Powered Static Website CMS

## Implementation Plan (read before code)

This document is the contract for the build. It is deliberately boring: the site is a
static artifact, the CMS is a spreadsheet, and Laravel is a build tool that runs off-line.

---

## 1. Architecture review (what actually exists)

```
Google Sheet "Website CMS"
        |  Sheets API (read only at build time)
        v
Laravel 12 / PHP 8.3  (local or CI runner — NOT on Cloudflare Pages)
  +-- Google OAuth (tokens encrypted at rest in storage/app)
  +-- GoogleSheetsService  -> raw rows
  +-- SpreadsheetSchemaService -> header/tab checks
  +-- DataValidationService -> normalized, validated CMS data
  +-- ContentBuilderService -> relationships, URLs, resolved R2 URLs
  +-- StaticSiteGenerator   -> Blade -> HTML + JSON + sitemap + robots + _redirects
  +-- R2StorageService (S3-compatible, AWS SDK)
  +-- PublishingService -> atomic swap of storage/app/generated/current -> artifact
        v
GitHub repo (artifact branch / committed output)
        v
GitHub Actions -> Wrangler -> Cloudflare Pages -> public
```

**Hard rules adopted**

- No MySQL/Postgres/SQLite. No Eloquent models, no migrations.
- No Google API call from generated JavaScript or from a visitor request.
- No credentials in generated output. Only `CLOUDFLARE_R2_PUBLIC_URL` image URLs appear.
- Laravel is never deployed to Pages. Pages serves files only.
- `preferences-reduced-motion` and a Skip Intro control are first-class, not afterthoughts.

## 2. Dependencies

| Need | Choice | Why |
|---|---|---|
| HTTP framework | Laravel 12 (`laravel/framework`) | Specified; Blade templating |
| Google API | `google/apiclient` v2 | Official Sheets/Drive/OAuth client |
| R2 | `aws/aws-sdk-php` v3 | R2 is S3-compatible; battle-tested signer |
| Images | PHP `ext-gd` (WebP if available) | No extra package; one small service |
| Frontend | Vanilla JS + GSAP + ScrollTrigger + Three.js via CDN | No JS build step; static output stays static |
| CSS | Hand-written CSS with custom properties | Smaller than Tailwind output, theme switching is trivial |
| Tests | PHPUnit (ships with Laravel) + mocked services | No real Google/Cloudflare credentials needed |

**Not added on purpose:** Redis, queues, databases, Inertia/Livewire, Tailwind build
chain, React/Vue, image CDN SDK. None are needed for a once-per-publish build.

## 3. Cloudflare Pages / Laravel limitations

- Pages cannot run PHP; only the generated `public/`-style artifact is uploaded.
- Redirects use a generated `_redirects` file (Pages-native) and `404.html` fallback.
- Spas-style slide transitions are pure client-side; every page also exists as real static
  HTML so crawlers and no-JS visitors get full content (progressive enhancement).
- R2 public delivery requires a custom domain or `r2.dev`; we use `CLOUDFLARE_R2_PUBLIC_URL`.
- Google Sheets API quota is per project; reading 12 tabs per publish is negligible but
  we still cache raw rows for the running publish and never poll on visits.
- Apps Script triggers can notify a build hook, but Sheet edits from the API may not fire
  `onEdit`; manual publish always exists.

## 4. Data flow (single publish)

1. `PublishWebsiteCommand` (or dashboard POST) -> `PublishingService::publish()`.
2. `GoogleAuthService::getClient()` refreshes token if expired, encrypts it back.
3. `GoogleSheetsService::readAll()` fetches the 12 tabs by name into row arrays.
4. `SpreadsheetSchemaService::verify()` reports missing tabs/columns.
5. `DataValidationService::validate()` returns `{ok, errors[], warnings[], data}`.
6. `ContentBuilderService::build()` links products<->categories, resolves sections,
   navigation trees, SEO overrides, and R2 image URLs.
7. `StaticSiteGenerator::generate()` renders all Blade templates into a staging dir,
   writes JSON, sitemap, robots, `_redirects`, copies css/js.
8. On success only: atomically swap `generated/current` symlink/dir, write manifest JSON,
   zip artifact, then call GitHub dispatch (if configured).
9. On failure: keep last `current`, write error log, dashboard shows failure. Rollback =
   point `current` at a previous manifest snapshot and re-dispatch.

## 5. Spreadsheet schema (12 tabs)

Documented in `docs/SPREADSHEET_SCHEMA.md` and seeded by
`resources/cms/template.json` (importable via the "create from template" flow).

| Tab | Key | Required columns (fixed order) |
|---|---|---|
| Settings | key | key,value,type,description,updated_at |
| Pages | page_id | page_id,title,slug,page_type,template,content,featured_image,meta_title,meta_description,meta_keywords,status,sort_order,updated_at |
| Products | product_id | product_id,product_name,slug,category_id,short_description,full_description,regular_price,sale_price,SKU,primary_image,gallery_images,featured,status,sort_order,meta_title,meta_description,created_at,updated_at |
| Categories | category_id | category_id,category_name,slug,description,category_image,parent_category_id,status,sort_order,meta_title,meta_description |
| Banners | banner_id | banner_id,title,subtitle,description,desktop_image,mobile_image,button_text,button_url,background_color,status,sort_order,start_date,end_date |
| Sections | section_id | section_id,page_slug,section_type,section_title,section_subtitle,section_description,section_data,background_color,text_color,status,sort_order |
| Navigation | menu_id | menu_id,menu_location,label,url,parent_menu_id,open_new_tab,status,sort_order |
| Testimonials | testimonial_id | testimonial_id,customer_name,customer_designation,customer_image,testimonial_text,rating,status,sort_order |
| Services | service_id | service_id,title,slug,description,icon,image,status,sort_order |
| SEO | page_slug | page_slug,meta_title,meta_description,meta_keywords,canonical_url,og_title,og_description,og_image,robots_index,robots_follow |
| Contact | contact_id | contact_id,label,value,type,icon,status,sort_order |
| Redirects | redirect_id | redirect_id,source_url,destination_url,redirect_type,status |

Extra Keyspace for the animated brand: `Settings` also understands
`enable_intro`, `countdown_seconds`, `theme`, `intro_video_url`, `intro_texture_url`,
`enable_heavy_animation`, `primary_color`, `secondary_color`, `accent_color`,
`default_font`. Unknown keys are allowed and ignored.

Validation: duplicate IDs/slugs, invalid category refs, bad prices, missing names,
bad image refs, invalid templates, invalid nav parents, invalid SEO flags. Strict mode
blocks publishing; warning mode publishes with a report. Invalid rows are never silently
dropped — they are listed with sheet name + row number.

## 6. Image strategy

- Sheets store R2 object keys or public URLs only — never binaries.
- Prefixes: `assets/logo/`, `assets/banners/`, `products/{id}/`, `categories/{id}/`,
  `services/{id}/`, `testimonials/`, `pages/`.
- Upload: validate size + MIME, sha256 hash dedupe, GD resize -> WebP, thumb + 2 responsive
  widths, alt text stored in a sidecar `.json` object.
- Replacement: upload new -> verify -> update sheet reference (operator does this in
  Sheets) -> publish -> only then queue old key for deletion after configurable retention.
- Never delete on "missing from sheet" — a partial API response must not destroy assets.

## 7. Publishing modes & safety

- Manual (always available), Apps-Script notification (mode B), approval gate (mode C).
- Draft = generate + preview under `generated/drafts/{id}`; Live = `generated/current`.
- Atomic swap + manifest with checksum; rollback re-points and re-dispatches.
- Failed deployment never touches `current`.

## 8. Phased build (each phase verified before the next)

- **Phase 1** Laravel foundation: composer.json, artisan, bootstrap, config, routes, env example.
- **Phase 2** Google OAuth + Sheets + Drive services, spreadsheet selection, template seeding.
- **Phase 3** Schema service + validation engine + sample data + fixtures.
- **Phase 4** R2 storage + image manager (upload, replace, dedupe, variants, metadata).
- **Phase 5** Content builder + static generator + JSON/sitemap/robots/redirects + artifact.
- **Phase 6** Frontend: obsidian theme, responsive catalogue, intro (particles/countdown/brand/doors), slide panels, carousel, search, filters, reduced-motion.
- **Phase 7** SEO + navigation + banners + sections + all 12 tabs wired into output.
- **Phase 8** Publishing dashboard (overview, publish, preview, images, validation, config).
- **Phase 9** GitHub Actions deploy + Cloudflare Pages config + custom domain docs.
- **Phase 10** Commands, tests, docs (18 required docs), cost notes.

## 9. Environment note

This workspace has Node/npm/git but **no PHP and no Composer**. Source is generated as a
complete Laravel project; `composer install` and `php artisan test` must be run on a
PHP 8.3+ machine. PHP syntax is written conservatively (no unverified bleeding-edge
features). The generated static site itself can be previewed with `npx serve`.
