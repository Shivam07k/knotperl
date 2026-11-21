# 1. Installation

Requirements: PHP 8.3+ with `dom`, `gd`, `json`, `zip`, `fileinfo`, Composer 2, Node 18+
(only for previewing static output). A database is **not** required.

```bash
git clone <your-repo> knots-and-perl-cms
cd knots-and-perl-cms
composer install
cp .env.example .env
php artisan key:generate
```

Create the writable directories (they exist in the repo with `.gitkeep` files):

```bash
mkdir -p storage/app/private storage/app/generated/drafts storage/app/temporary \
         storage/app/backups storage/app/publishing-logs storage/framework/cache/data \
         storage/framework/sessions storage/framework/views storage/logs
```

Fill in `.env` (see [ENVIRONMENT_CONFIG.md](ENVIRONMENT_CONFIG.md)), then check the engine:

```bash
php artisan cms:template          # lists the 12 tabs and their columns
php artisan route:list           # dashboard routes
php artisan test                 # full suite, no real Cloudflare/GitHub needed
```

Start the dashboard:

```bash
php artisan serve
```

Open `http://localhost:8000`. The landing page is the publishing dashboard, not the
public website. The public website only exists after a publish.

### Verification checklist

- `php artisan test` is green.
- `php artisan cms:template` prints all 12 tabs.
- The dashboard loads and shows the publish status.
- No `.env`, token file or secret is inside `public/` or `site/`.
