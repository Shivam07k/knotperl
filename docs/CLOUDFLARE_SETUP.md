# Cloudflare setup

One-time setup for the person who owns the infrastructure. Editors never see any
of these credentials.

## 1. Import the existing content

Copy the already-imported `storage/app/content.json` from the project (or restore it
from a backup). It contains all twelve sections as a single content file. If you ever
need to repeat the one-time export from the old sheet, run:

```bash
php artisan cms:content:import --out=storage/app/content.json
```

## 2. Create R2 storage

1. Create an R2 bucket named `site-cms` (or change `bucket_name` in `worker/wrangler.toml`).
2. Upload the imported file as `content.json` at the bucket root:
   ```bash
   npx wrangler r2 object put site-cms/content.json --file=storage/app/content.json
   ```
3. Create an R2 API token with read/write access to that bucket.
4. Enable a public domain for the bucket and note its URL (for example
   `https://images.example.com`). Uploaded pictures will use it.

## 3. Deploy the worker

From `worker/`:

```bash
npx wrangler secret put ADMIN_PASSWORD
npx wrangler secret put SESSION_SECRET
npx wrangler secret put GITHUB_TOKEN
npx wrangler secret put STATUS_SECRET
npx wrangler deploy
```

Edit `wrangler.toml` first:

- `ADMIN_ORIGIN` — the admin Pages URL.
- `R2_PUBLIC_URL` — the bucket public URL.
- `GITHUB_REPOSITORY` — `owner/repo`.
- `GITHUB_WORKFLOW` — `deploy.yml`.

The worker holds all secrets. The browser only talks to its HTTPS API.

## 4. Deploy the admin

Deploy the `admin/` directory as its own Cloudflare Pages site. Before deploying,
set `WORKER_URL` and `IMAGE_BASE_URL` in `admin/config.js` to the deployed worker
URL and bucket public URL.

## 5. Configure GitHub Actions

The `Publish` button dispatches `.github/workflows/deploy.yml`. Add these repository
secrets:

| Secret | Value |
| --- | --- |
| `CF_ACCOUNT_ID` | Cloudflare account ID |
| `R2_ACCESS_KEY_ID` | R2 API token access key |
| `R2_SECRET_ACCESS_KEY` | R2 API token secret |
| `R2_BUCKET` | Bucket name, e.g. `site-cms` |
| `CLOUDFLARE_API_TOKEN` | Token with Pages deploy permission |
| `CLOUDFLARE_PAGES_PROJECT` | Visitor site Pages project name |
| `CLOUDFLARE_R2_PUBLIC_URL` | Bucket public URL |
| `APP_KEY` | Laravel application key |
| `STATUS_URL` | `<worker URL>/api/status` |
| `STATUS_SECRET` | Same value as the worker's `STATUS_SECRET` |

The `GITHUB_TOKEN` worker secret needs permission to dispatch the workflow.

## 6. Verify

Sign in to the admin, change a title, change a picture, add a page and press
**Publish**. The status line should move from *Publishing…* to *Website is live.*
and the visitor site should keep its existing design.
