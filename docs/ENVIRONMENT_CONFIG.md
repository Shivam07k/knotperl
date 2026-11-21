# 11. Environment configuration

Copy `.env.example` to `.env` and fill in only what you use.

| Variable | Purpose |
|---|---|
| `APP_NAME`, `APP_ENV`, `APP_KEY`, `APP_DEBUG`, `APP_URL` | Laravel basics; `php artisan key:generate` sets `APP_KEY` |
| `CMS_LOCAL_MODE` | `true` runs the dashboard offline against fixture data (no Cloudflare/GitHub calls); `false` (default) uses real services. See [Local development](LOCAL_DEVELOPMENT.md) |
| `CMS_CONTENT_SOURCE` | `json` (default); the build reads the content file |
| `CMS_CONTENT_JSON` | Path to the content file, normally `storage/app/content.json` |
| `ADMIN_PASSWORD_HASH` | bcrypt hash for the publishing dashboard login; required, else the dashboard refuses to load. Generate with `php -r "echo password_hash('your-password', PASSWORD_DEFAULT);"` |
| `CLOUDFLARE_ACCOUNT_ID` | Used for the R2 endpoint and Pages API |
| `CLOUDFLARE_R2_ACCESS_KEY_ID`, `CLOUDFLARE_R2_SECRET_ACCESS_KEY`, `CLOUDFLARE_R2_BUCKET` | R2 S3-compatible access |
| `CLOUDFLARE_R2_PUBLIC_URL` | Public image base URL |
| `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_PAGES_PROJECT` | Direct Pages upload |
| `GITHUB_REPOSITORY`, `GITHUB_DEPLOY_TOKEN`, `GITHUB_WORKFLOW`, `GITHUB_BRANCH` | Workflow dispatch |
| `PUBLISH_MODE` | `manual`, `notify`, or `approval` |
| `PUBLISH_STRICT` | `true` blocks publishing on validation errors |
| `PUBLISH_WEBHOOK_SECRET` | HMAC secret for `POST /webhook/build` |
| `PUBLISH_KEEP_BACKUPS`, `IMAGE_RETENTION_DAYS` | Rollback history and safe image deletion |
| `LIVE_SITE_URL` | Canonical base URL for SEO |
| `IMAGE_MAX_BYTES`, `IMAGE_QUALITY` | Upload limits and WebP quality |

## Admin login and local mode

The publishing dashboard and admin editor always require a login. Set
`ADMIN_PASSWORD_HASH` to a bcrypt hash of your password (leave the plaintext out of
`.env`). Sessions use the file driver — no database. The login route is throttled.

`CMS_LOCAL_MODE=true` binds content, R2 and deployment services to local fixture
implementations, so you can validate, generate and preview without credentials.
It never publishes to the live site and refuses real publish and rollback.

The browser admin signs in to the Cloudflare Worker, not to this Laravel dashboard.
The worker holds the admin password and all deployment credentials.

Security notes:

- `.env` is gitignored and read only by PHP.
- No variable is emitted into generated JS or JSON; only the public R2 URL appears. Form collector URLs appear in generated HTML/CSP; no secrets or credentials are emitted.
- Use distinct, least-privilege tokens for R2 and Pages.
