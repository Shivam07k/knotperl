# Front-end CMS on Cloudflare (no Google, no server)

Date: 2026-11-21
Status: approved 2026-11-21 — implementation plan to follow

## Goal

Edit all site content and upload images from a browser. One click publishes to the
live Cloudflare Pages site. The visitor-facing design stays byte-for-byte the same.
Google Sheets, Google OAuth, Google Cloud Console and the local PHP server are no
longer part of normal operation.

## Why the Google Console existed

It was never needed by the design. It was the price of choosing Google Sheets as the
content store: reading/writing Sheets requires an OAuth app, consent screen, test
users, scopes and token files. Remove Sheets as the source of truth and all of that
goes away. Cloudflare Pages + R2 were already wired into the app; they only need
API credentials.

## Decisions from discussion

| Decision | Choice |
| --- | --- |
| Content source | JSON content file replaces Google Sheets |
| Admin UI | Static browser app hosted on Cloudflare Pages |
| Server owned by user | None |
| Secret holder | Cloudflare Worker (serverless, on the existing Cloudflare account) |
| Site rebuild | Reuse the existing Laravel generator and Blade templates unchanged |
| Images | Uploaded from the browser to Cloudflare R2 |
| Google | Removed from the admin and publish path |

## Architecture

```
Browser admin (static, Cloudflare Pages)
        |
        | HTTPS (password + content + images)
        v
Cloudflare Worker  -- secrets: admin password, R2 keys, GitHub token
        |
        +--> R2: save content JSON + uploaded images
        |
        +--> GitHub Actions: workflow_dispatch
                  |
                  +--> checkout repo (Blade templates + generator)
                  +--> download content JSON from R2
                  +--> run existing generator (PHP)
                  +--> upload built zip via Cloudflare Pages Direct Upload API
```

The browser never sees a Cloudflare API token or a GitHub token. The Worker holds
them. Nothing runs on the user's machine.

## Data model

One JSON file with the same shape as the current sheet export: an object keyed by
the 12 tab names (`Settings`, `Pages`, `Products`, `Sections`, `Navigation`,
`Categories`, `Banners`, `Testimonials`, `Services`, `SEO`, `Contact`,
`Redirects`), each an array of row objects. This is exactly what
`ContentBuilderService::build()` already accepts, so the generator and templates
do not change.

Image fields keep storing R2 keys (e.g. `assets/products/pr001/main.jpg`).
`R2StorageService::url()` already turns those into public URLs and stays unchanged.

## Form schema (change later without code changes)

Admin forms are generated from `admin/schema.json`, not hard-coded in JavaScript.
The file lists the three screens and their fields, e.g.
`{"key":"title","label":"Page title","type":"text"}`. Changing a label, hiding a
field or adding a field that an existing template already supports is a one-line
edit to that file — no PHP, generator or admin JavaScript change.

Limit: a genuinely new field only appears on the visitor site if a template has a
place for it. Adding such a place is one small Blade template edit, not a rewrite
of the system.

## Admin capabilities (only these)

The admin is deliberately simple enough for any non-technical person. Three screens:

1. **Pages** — a list of the site's pages. Click one to edit its title, text and
   pictures. Button: **Add a page**.
2. **Products** — a list of items. Click one to edit its name, price, description
   and pictures. Button: **Add a product**.
3. **Settings** — brand name, logo, colours and contact details.

On every screen: **Change picture** opens a file picker. One big **Publish** button
makes the saved changes live. A short status line says "Published" or the problem
in plain English.

The admin must never show: spreadsheet tabs, sheet IDs, JSON, R2 keys, slugs, API
tokens, OAuth screens, or validation jargon. Those stay internal. JSON export is
one quiet "Download backup" link, not a main action.

## Required changes

1. **JSON content source** — add a small read path so the existing generator reads
   the downloaded content JSON instead of Google Sheets. Reuse the local-fixture
   mechanism already present (`cms.local_fixture_source`); no new abstraction.
2. **Publish command** — one CLI entry point that loads JSON content from R2 (or a
   downloaded file), runs `PublishingService`, and produces the static site.
3. **Cloudflare Worker** — endpoints: sign in, get content, save content, upload
   image, trigger publish, get publish status. Password checked against a Worker
   secret; session kept in an HttpOnly cookie.
4. **Admin static app** — three screens (Pages, Products, Settings) rendered from
   `admin/schema.json`, with plain labels, an image picker, one Publish button and
   a status line. Deployed as a separate Pages site (e.g. `admin.<domain>`).
5. **GitHub Actions workflow** — checkout, fetch content from R2, run the publish
   command, upload to Cloudflare Pages. Cloudflare credentials live in GitHub
   Secrets.
6. **Remove Google from the admin** — drop the connect/disconnect UI and the
   spreadsheet picker; delete the Google service/config/routes once nothing else
   references them.
7. **Docs** — replace `docs/UPDATING_CONTENT.md` with the browser-admin guide and
   a one-time setup checklist.

## Out of scope

- Any visual/CSS/animation change to the visitor site (intro timing, nav z-index,
  carousels, layout)
- Editing content in a spreadsheet
- Running or managing a PHP server
- Migrating old spreadsheet rows automatically (initial JSON is seeded from the
  current fixture/current spreadsheet once)

## One-time setup for the user

1. Create/keep a Cloudflare R2 bucket + API token
2. Create a Cloudflare API token with Pages:Edit
3. Add the Worker secrets (admin password, R2 keys, GitHub token)
4. Add R2 + Cloudflare credentials to GitHub Secrets
5. Point `admin.<domain>` at the static admin Pages project

No Google Console step at any point.

## Tests / verification

- Generator output from the JSON content source matches the current fixture output
  (proves the design is unchanged)
- Worker: auth rejects wrong password; content save/read round-trip; image upload;
  publish trigger requires auth
- Admin: form saves editable fields; upload inserts an R2 URL; publish shows status
- Full existing test suite still passes after Google removal (stale tests updated)

## Risks

- Two platforms to configure once: Cloudflare (Worker/R2/Pages) and GitHub (Actions).
  This is the price of reusing the PHP generator without a server.
- If the GitHub Action fails, Publish reports the error; the previous live site stays.
- Content is only editable through the admin (no spreadsheet fallback). Keep an
  export button so the JSON can be downloaded as a backup.
