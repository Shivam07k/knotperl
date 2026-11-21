# Knots & Perl — browser-managed static CMS

A Laravel 12 build engine renders the existing visitor site from a JSON content file
and publishes static files to Cloudflare Pages. Editors use a small browser admin;
no spreadsheet, database or local server is needed.

- Content is edited in the browser: Pages, Products and Settings.
- A Cloudflare Worker holds all secrets and powers the admin API.
- GitHub Actions rebuilds and deploys the unchanged site when **Publish** is clicked.
- Visitors receive only static HTML, CSS, JavaScript and images.

Quick start (PHP 8.3+ and Composer required):

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan cms:validate
php artisan test
```

Deploy: [docs/DEPLOYING.md](docs/DEPLOYING.md) ·
Setup: [docs/CLOUDFLARE_SETUP.md](docs/CLOUDFLARE_SETUP.md) ·
Editing: [docs/UPDATING_CONTENT.md](docs/UPDATING_CONTENT.md) ·
Docs index: [docs/DOCS_INDEX.md](docs/DOCS_INDEX.md)
