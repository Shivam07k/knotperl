# 12. Local development

```bash
php artisan serve            # dashboard at http://localhost:8000
php artisan cms:validate     # check the content file
php artisan cms:generate --preview   # draft in storage/app/generated/drafts/
php artisan cms:publish --no-deploy  # local live build, no deployment
php artisan test             # all tests with mocked Cloudflare/GitHub
```

## Local fixture mode (no credentials)

Set `CMS_LOCAL_MODE=true` in `.env` to run the whole workflow offline with fixture data
instead of R2 or GitHub. Set `ADMIN_PASSWORD_HASH` too (see
[Environment configuration](ENVIRONMENT_CONFIG.md)) and log in at `/login` — the
dashboard is always behind the operator login.

In this mode:

- Content, R2 and deployment are bound to local implementations. Content reads a
  persistent working copy at `storage/app/generated/local-fixture/sheet-data.json`,
  seeded once from `tests/Fixtures/sheet-data.json`. Admin edits therefore survive
  across requests; no network calls are made.
- The dashboard shows a persistent **"LOCAL FIXTURE MODE — nothing is live"** banner.
- **Reset fixture** on the dashboard (or `POST /local/reset`) restores the working copy
  from the fixture — useful after experimenting with the admin editor.
- **Preview works** (`php artisan cms:generate --preview` and the dashboard's Preview
  button). Real publishing with deployment and rollback are **refused** in local mode;
  only a local `--no-deploy` build is allowed. Nothing touches the live site.

## Previewing and exporting

Previewing the generated site locally (the built pages use absolute `/css` and `/js`
paths, so serve the generated directory as the web root):

```bash
npx serve storage/app/generated/current
```

Or export to `site/` and serve that directory:

```bash
php artisan cms:export
npx serve site
```

`cms:export` copies a successful generated build (including the generated `theme.css`)
into `site/` and is the trigger for a Pages deploy. Local-mode previews never touch
`site/`.

Tests never call Cloudflare or GitHub: services are mocked, and
`tests/Fixtures/sheet-data.json` is derived from the template sample data.

Editing the CMS: change `resources/cms/schema.json` for columns/validation rules and
`resources/cms/template.json` for sample rows. Re-run `php artisan cms:template` to see
the resulting tab list. Blade templates live under `resources/views/site/`.
