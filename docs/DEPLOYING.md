# Deploying and how the site works

This is the single guide for the live setup: what is deployed, where it lives, how a
Publish reaches visitors, and what to do when you change something.

## Live setup

| Piece | Lives at | Deployed from |
|---|---|---|
| Visitor site | `https://knots-and-perl.pages.dev` | GitHub Actions generates it, then deploys it to Cloudflare Pages |
| Admin | `https://knots-and-perl-admin.pages.dev` | The `admin/` folder, deployed as its own Pages site |
| Worker API | `https://site-admin-api.shivamperfect-98.workers.dev` | `worker/`, via `npx wrangler deploy` |
| Content + pictures | R2 bucket `site-cms`, public base `https://pub-be994af6575d4a3d9d77c4315d65d009.r2.dev` | Browser admin writes it; the Worker uploads it |
| Source + build engine | GitHub `Shivam07k/knotperl`, branch `main` | This repository |

Cloudflare account ID: `a151528af43d01bdbdd3e33d1730ab7a`.

Three separate deploy targets. They do **not** deploy each other. A push to GitHub
only stores code — nothing goes live until the right deploy step runs.

## How it works

```
   Editor browser                 Cloudflare Worker                R2
   admin.pages.dev  ──login/content/image/publish──▶  site-admin-api.workers.dev
          │                              │                      │
          │                              ├── reads/writes ──────▶│ content.json
          │                              └── uploads pictures ─▶│ assets/*
          │
          └── Publish ──▶ Worker dispatches GitHub Actions
                                  │
                                  ├── downloads content.json from R2
                                  ├── Laravel generator renders static HTML
                                  └── Pages deploy ──▶ visitor site
```

Key facts:

- **Content lives in R2, not in git.** The repository has no copy that editors
  maintain. GitHub Actions downloads the current `content.json` at build time.
- **The Worker holds every secret.** The browser only talks to its HTTPS API and
  never sees Cloudflare or GitHub tokens.
- **The design lives in the repository.** Blade templates, CSS and the generator
  are the single source of truth, so the visitor design cannot drift from admin
  edits.
- **The admin is schema-driven.** Every form field comes from `admin/schema.json`.
  No JavaScript change is needed to rename or add a field.
- **The login cookie is `SameSite=None; Secure`.** The admin (`pages.dev`) and the
  Worker (`workers.dev`) are different sites. `Strict` or `Lax` makes the browser
  drop the cookie on the next request, which shows as `Please sign in again.`
  Do not change it back.

## What happens when someone presses Publish

1. Admin saves the current content to the Worker (`PUT /api/content`).
2. Worker writes `content.json` to R2.
3. Admin calls `POST /api/publish`. Worker dispatches
   `.github/workflows/deploy.yml` through GitHub with `ref: main`.
4. Worker records status `publishing` in R2.
5. GitHub Actions checks out the repo, installs PHP dependencies, downloads
   `content.json` from R2 using the S3 credentials, and runs
   `php artisan cms:publish --no-deploy` to generate `storage/app/generated/current`.
6. `cloudflare/wrangler-action@v4` runs `pages deploy` on that folder.
7. The workflow posts `published` back to the Worker's `/api/status` endpoint
   with the shared `STATUS_SECRET`. Failure posts `failed` instead.
8. Admin polls `/api/status` and shows *Website is live.* or the error state.

The workflow is `workflow_dispatch` only. There is no `on: push` trigger.

## One-time setup from scratch

### 1. GitHub repository and secrets

Create the repository, push `main`, then add repository secrets under
**Settings → Secrets and variables → Actions** (not environment secrets):

`CF_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`,
`CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_PAGES_PROJECT`,
`CLOUDFLARE_R2_PUBLIC_URL`, `APP_KEY`, `STATUS_URL`, `STATUS_SECRET`.

See the [secrets reference](#secrets-reference) below for each value.

### 2. Cloudflare R2

1. Create bucket `site-cms`.
2. Upload the content file:
   ```bash
   npx wrangler r2 object put site-cms/content.json --file=storage/app/content.json
   ```
3. Create an R2 API token with read/write access to that bucket. Its Access Key
   ID and Secret become the two GitHub R2 secrets.
4. Enable a public domain on the bucket and use that URL as
   `CLOUDFLARE_R2_PUBLIC_URL` and the Worker's `R2_PUBLIC_URL`.

### 3. Cloudflare Pages projects

Create two **classic Direct Upload** projects:

```bash
npx wrangler pages project create knots-and-perl --force
npx wrangler pages project create knots-and-perl-admin --force
```

The visitor project name goes in `CLOUDFLARE_PAGES_PROJECT` (`knots-and-perl`).
The Worker API token needs Pages edit permission.

### 4. Worker

Edit `worker/wrangler.toml` first: `account_id`, the `CONTENT` R2 binding,
`ADMIN_ORIGIN`, `R2_PUBLIC_URL`, `GITHUB_REPOSITORY`, `GITHUB_WORKFLOW`.

Then, from `worker/`:

```bash
npx wrangler secret put ADMIN_PASSWORD
npx wrangler secret put SESSION_SECRET
npx wrangler secret put GITHUB_TOKEN
npx wrangler secret put STATUS_SECRET
npx wrangler deploy
```

The `GITHUB_TOKEN` needs `repo` scope so it can dispatch the workflow.

Run Wrangler from `worker/`, not the repository root: the root `.env` has an empty
`CLOUDFLARE_API_TOKEN` that can shadow the Wrangler credentials.

### 5. Admin

Set `WORKER_URL` and `IMAGE_BASE_URL` in `admin/config.js` to the Worker URL and
the R2 public URL, then deploy the folder:

```bash
npx wrangler pages deploy admin --project-name=knots-and-perl-admin
```

### 6. Verify

Sign in at the admin URL, change a title, press **Publish**, and wait for
*Website is live.* Then open the visitor URL and confirm the change.

## Everyday changes

| What you change | Where | How it goes live |
|---|---|---|
| Text, pictures, pages, products, settings | Browser admin | Press **Publish**. No git, no terminal. |
| Visitor design, Blade templates, CSS, generator | This repo | Commit + push, then press **Publish** in the admin (or run the workflow from GitHub). Push alone does not deploy. |
| Worker API, auth, upload or publish logic | `worker/` in this repo | Commit + push, then `npx wrangler deploy` from `worker/`. |
| Admin screens, fields, labels | `admin/` in this repo | Commit + push, then `npx wrangler pages deploy admin --project-name=knots-and-perl-admin`. |
| Content file / large recovery | R2 | Upload `content.json` to the bucket, then press **Publish**. |

For a code bug fix the loop is: write a failing test, fix the code, run all
tests, commit, push, deploy the affected target, confirm the site returns 200.

## Secrets reference

**Worker secrets** (`npx wrangler secret put <NAME>`, from `worker/`):

| Name | Purpose |
|---|---|
| `ADMIN_PASSWORD` | Admin login password |
| `SESSION_SECRET` | Signs the eight-hour session cookie |
| `GITHUB_TOKEN` | Dispatches the Publish workflow |
| `STATUS_SECRET` | Verifies status callbacks from GitHub Actions |

**GitHub repository secrets:**

| Name | Value |
|---|---|
| `CF_ACCOUNT_ID` | Cloudflare account ID |
| `R2_ACCESS_KEY_ID` | R2 token access key |
| `R2_SECRET_ACCESS_KEY` | R2 token secret |
| `R2_BUCKET` | `site-cms` |
| `CLOUDFLARE_API_TOKEN` | Token with Workers/Pages/R2 edit permissions |
| `CLOUDFLARE_PAGES_PROJECT` | `knots-and-perl` |
| `CLOUDFLARE_R2_PUBLIC_URL` | R2 public base URL |
| `APP_KEY` | Laravel application key |
| `STATUS_URL` | `<worker URL>/api/status` |
| `STATUS_SECRET` | Same value as the Worker's `STATUS_SECRET` |

Never commit `.env`, tokens or keys. The Worker's `GITHUB_TOKEN` is a classic PAT
with a 90-day expiry; rotate it with `npx wrangler secret put GITHUB_TOKEN`.

## Tests

Run all three suites before pushing a change:

```bash
php artisan test
node --test worker/test/*.test.js
node --test admin/test/*.test.js
```

On this Windows machine the PHP binary is at:

```text
C:\Users\hp\AppData\Local\Microsoft\WinGet\Packages\PHP.PHP.8.3_Microsoft.Winget.Source_8wekyb3d8bbwe\php.exe
```

## More detail

- [Cloudflare setup](CLOUDFLARE_SETUP.md) — original infrastructure checklist
- [GitHub setup](GITHUB_SETUP.md) — repository layout and secrets
- [Updating content](UPDATING_CONTENT.md) — editor guide
- [Troubleshooting](TROUBLESHOOTING.md) — error messages and local debug commands
