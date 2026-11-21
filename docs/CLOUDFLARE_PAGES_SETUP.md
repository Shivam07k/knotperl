# 10. Cloudflare Pages setup

1. Cloudflare dashboard → **Workers & Pages → Create → Pages**.
2. Choose **Direct Upload** if the Laravel engine pushes the zip, or **Connect to Git**
   if you commit `site/` and use GitHub Actions.
3. Project name: `knots-and-perl` (store it in `CLOUDFLARE_PAGES_PROJECT`).
4. Production branch: `main`. Build command: **none** — the artifact is already static.
   Output directory: `site` when using Git, irrelevant for direct upload.
5. Add a custom domain under **Custom domains**, e.g. `knotsandperl.com`. HTTPS is
   automatic. Set `LIVE_SITE_URL` to this URL for canonical tags and the sitemap.
6. Check **Deployments** for logs and history. Failed deployments do not affect the
   previous live version.

Cloudflare Pages serves `/about-us` from `about-us/index.html`, which is why the
generator writes clean `directory/index.html` paths. `_redirects` implements 301/302
rules and `_headers` sets caching, frame and security headers.

Pages hosts static files only. Laravel never runs there.
