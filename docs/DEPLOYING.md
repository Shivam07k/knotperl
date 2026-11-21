# Deploying and how the site works

This is the single guide for the live setup: what is deployed, where it lives, how a
Publish reaches visitors, and what to do when you change something.

## Contents

- [Step by step: what you do and how](#step-by-step-what-you-do-and-how) — start here
  for daily work
- [One-time setup from scratch](#one-time-setup-from-scratch) — rebuilding the setup
- [Everyday changes](#everyday-changes) — which deploy step each change needs
- [Secrets reference](#secrets-reference)
- [Tests](#tests)

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

## Step by step: what you do and how

### A. Change content and publish (the normal daily job)

1. Open Chrome or Edge and go to `https://knots-and-perl-admin.pages.dev`.
2. Type the admin password in the **Password** box and click **Sign in**.
   - If you land back on the login box, the eight-hour session expired or the
     Worker was just redeployed. Sign in again.
3. Click the screen you need at the top: **Pages**, **Products** or **Settings**.
4. Change the text in the boxes on that screen. Nothing is live yet.
5. To change a picture, click **Change picture** beside it, choose a JPG, PNG, WEBP
   or GIF up to 8 MB, then click **Open**. The preview updates right away.
6. To add an entry, click **Add a page** or **Add a product**, fill in the empty
   row, and it appears in the list.
7. Click **Publish** at the top.
8. Watch the status line under the button. It shows *Publishing…* for roughly
   1–2 minutes, then *Website is live.*
9. Open `https://knots-and-perl.pages.dev`, press **Ctrl+F5** to bypass the cache,
   and check your change.

If it says *Publishing failed*, follow section C and read the red step in the
GitHub run. Nothing was deployed in that case; your edits are still saved.

### B. Fix a code bug

1. Tell me in this chat what you clicked, what you expected and what happened.
2. I write a test that fails because of the bug, fix the code, run all three test
   suites, commit and push to `main`. You do not need to touch git.
3. I deploy the affected target:

   | Bug is in | Deploy step |
   |---|---|
   | Visitor design, templates, CSS, generator | Publish via the Worker API (or you do section C) |
   | Worker API, login, images, publish logic | `npx wrangler deploy` from `worker/` |
   | Admin screens, fields, labels | `npx wrangler pages deploy admin --project-name=knots-and-perl-admin` |

4. I confirm both URLs return 200. You hard-refresh the page and check the bug is gone.
5. A plain `git push` never deploys the visitor site. It still needs Publish.

### C. Run Publish manually from GitHub (optional)

Useful if the admin is unavailable.

1. Open `https://github.com/Shivam07k/knotperl/actions`.
2. Click **Publish website** in the left-hand list.
3. Click **Run workflow** on the right side.
4. Leave branch **main** selected and click the green **Run workflow** button.
5. Click the new row at the top, then click the **publish** job.
6. Wait until every step is green. The important ones are **Download content**,
   **Generate site** and **Deploy to Cloudflare Pages**.
7. Open the visitor URL and press **Ctrl+F5**.

### D. Check that a deploy worked

| Check | How |
|---|---|
| Admin loads | Open the admin URL and sign in; the three screens appear |
| Content was saved | Change a title, reload the admin page, the title is still changed |
| Visitor site is live | Open the visitor URL, Ctrl+F5, see your change |
| Publish result | Watch the admin status line: *Publishing…* then *Website is live.* |
| Build log | `https://github.com/Shivam07k/knotperl/actions`, newest **Publish website** row |

### E. Download a backup

1. In the admin, click **Download backup**.
2. The browser saves a JSON file. Keep it somewhere safe before large edits.
3. To restore, upload that file to R2 as `content.json`, then press **Publish**.

## One-time setup from scratch

Only do this if you are rebuilding the infrastructure. The live setup is already done.

### 1. GitHub repository and secrets

1. Create the repo `knotperl` under the `Shivam07k` account and push branch `main`.
2. Open `https://github.com/Shivam07k/knotperl/settings/secrets/actions`.
3. Click **New repository secret**.
4. Put the exact name in **Name**, paste the value in **Secret**, click **Add secret**.
5. Repeat for all ten: `CF_ACCOUNT_ID`, `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`,
   `R2_BUCKET`, `CLOUDFLARE_API_TOKEN`, `CLOUDFLARE_PAGES_PROJECT`,
   `CLOUDFLARE_R2_PUBLIC_URL`, `APP_KEY`, `STATUS_URL`, `STATUS_SECRET`.
6. Check they are **Repository secrets**, not Environment secrets, with no extra
   spaces in the names.

See the [secrets reference](#secrets-reference) below for each value.

### 2. Cloudflare R2

1. In the Cloudflare dashboard go to **Workers & Pages → R2 → Create bucket** and
   name it `site-cms`.
2. Upload the content file. From a terminal in the project folder:
   ```bash
   npx wrangler r2 object put site-cms/content.json --file=storage/app/content.json
   ```
3. Go to **R2 → Manage R2 API tokens → Create API token**, allow object read and
   write on `site-cms`, then copy the **Access Key ID** and **Secret Access Key**
   into the two GitHub secrets from step 1.
4. On the bucket open **Settings → Public development URL → Enable**. Put that URL
   in `CLOUDFLARE_R2_PUBLIC_URL` in GitHub and `R2_PUBLIC_URL` in
   `worker/wrangler.toml`.

### 3. Cloudflare Pages projects

Create two **classic Direct Upload** projects. From a terminal in the project folder:

```bash
npx wrangler pages project create knots-and-perl --force
npx wrangler pages project create knots-and-perl-admin --force
```

Or in the dashboard: **Workers & Pages → Create → Pages → Direct Upload**, type the
project name and click **Create**. Do it for both names.

The visitor project name goes in `CLOUDFLARE_PAGES_PROJECT` (`knots-and-perl`).
The Cloudflare API token needs Pages edit permission.

### 4. Worker

1. Open `worker/wrangler.toml` and set `account_id`, the `CONTENT` R2 binding,
   `ADMIN_ORIGIN`, `R2_PUBLIC_URL`, `GITHUB_REPOSITORY` and `GITHUB_WORKFLOW`.
2. Open a terminal inside `worker/`. Run each command below. Wrangler asks you to
   type or paste the value:
   ```bash
   npx wrangler secret put ADMIN_PASSWORD
   npx wrangler secret put SESSION_SECRET
   npx wrangler secret put GITHUB_TOKEN
   npx wrangler secret put STATUS_SECRET
   npx wrangler deploy
   ```
3. Copy the printed `https://...workers.dev` URL. It goes in `admin/config.js` as
   `WORKER_URL`, in GitHub as `STATUS_URL` (add `/api/status` to the end), and in
   `wrangler.toml` as `ADMIN_ORIGIN` for the admin URL.
4. The `GITHUB_TOKEN` needs classic `repo` scope so it can dispatch the workflow.
   It expires after 90 days.

Run Wrangler from `worker/`, not the repository root: the root `.env` has an empty
`CLOUDFLARE_API_TOKEN` that can shadow the Wrangler credentials.

### 5. Admin

1. Open `admin/config.js` and set `WORKER_URL` to the Worker URL and
   `IMAGE_BASE_URL` to the R2 public URL.
2. From a terminal in the project folder run:
   ```bash
   npx wrangler pages deploy admin --project-name=knots-and-perl-admin
   ```
3. Open the printed URL and sign in.

### 6. Verify

Follow section A above: sign in, change a title, press **Publish**, wait for
*Website is live.*, then hard-refresh the visitor URL and see the change.

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
