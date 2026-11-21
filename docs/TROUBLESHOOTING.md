# 17. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `Please sign in again` | Session expired (eight hours), wrong worker URL, or the browser blocked the cross-site cookie. The login cookie must stay `SameSite=None` because admin and worker are on different domains. |
| `Publishing is not configured yet` | Set the worker's `GITHUB_TOKEN` secret and GitHub secrets from `CLOUDFLARE_SETUP.md`. |
| `Missing section: Products` or missing columns | The content file was edited outside the admin. Restore it from a backup. |
| `Duplicate slug` / `Category ID does not exist` | Fix the referenced row; validation errors block publishing in strict mode. |
| `Image reference ... is invalid` | Use an R2 key (`products/pr001/main.webp`) or a full `https://` URL. |
| `Cloudflare R2 credentials are not configured` | Fill the four R2 variables in `.env`, especially the Account ID used to derive the endpoint. |
| `No deployment target configured` | Set Cloudflare Pages token/project or GitHub token/repository, or use `--no-deploy`. |
| `Cloudflare Pages deployment failed` | Check the token's Pages edit permission and project name; see the deployment log. |
| `404` on generated pages | Cloudflare Pages must serve the artifact root; ensure `site/` contains `index.html` and per-page `index.html` files. |
| Redirects not working | Cloudflare Pages reads `_redirects` at the artifact root; check source/destination paths. |
| Intro animation does not play | `enable_intro` is false, `countdown_seconds` is 0, reduced motion is enabled, or the session flag was set. |
| Tests fail with missing extension | Install PHP `gd`, `dom`, `zip` and `fileinfo` extensions. |

Debug commands:

```bash
php artisan cms:validate --json
php artisan cms:sync
php artisan cms:generate --preview
tail -f storage/app/publishing-logs/publishing.jsonl
```
