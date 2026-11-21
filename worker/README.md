# Admin Worker

Invisible backend for the browser admin. It keeps all secrets off the admin site.

Deploy from this directory with `npx wrangler deploy`, after editing
`wrangler.toml` (bucket name, admin origin, image URL, GitHub repo/workflow).

Set secrets once:

```bash
npx wrangler secret put ADMIN_PASSWORD
npx wrangler secret put SESSION_SECRET
npx wrangler secret put GITHUB_TOKEN
npx wrangler secret put STATUS_SECRET
```

The R2 bucket must contain `content.json` (created by the one-time sheet import)
and will receive uploaded images under `assets/`.

Tests: `node --test worker/test/*.test.js` from the project root.
