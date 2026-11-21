# GitHub repository setup

Repository layout:

```
app/            Laravel build engine (private)
admin/          Browser admin, deployed separately to Cloudflare Pages
worker/         Cloudflare Worker that holds secrets
config/         Engine config
resources/cms/  Content schema + template
resources/views/ Blade templates
public/         Dashboard assets + source CSS/JS copied into builds
storage/        Content file, drafts, current build, logs (gitignored)
.github/workflows/deploy.yml
```

## Secrets

Add the secrets listed in [Cloudflare setup](CLOUDFLARE_SETUP.md) under
**Settings → Secrets and variables → Actions**. The worker's `GITHUB_TOKEN` secret
needs permission to dispatch the workflow; all other credentials stay in GitHub
or Cloudflare, never in the admin site.

## Publishing

Editors press **Publish** in the browser admin. The worker dispatches
`workflow_dispatch`; the workflow downloads `content.json` from R2, generates the
site and deploys it with `cloudflare/wrangler-action@v4` using `pages deploy`.

For a local dry run:

```bash
CMS_CONTENT_SOURCE=json CMS_CONTENT_JSON=storage/app/content.json \
  php artisan cms:publish --no-deploy
```

Never commit `.env`, R2 keys or API tokens into the repository.
