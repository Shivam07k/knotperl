# 8. R2 custom domain for images

R2 buckets have a temporary `r2.dev` URL, but a stable custom domain is recommended.

1. In the bucket → **Settings → Custom domains → Connect domain**, e.g.
   `images.knotsandperl.com`.
2. Add the DNS CNAME record Cloudflare shows you. Cloudflare provisions HTTPS
   automatically.
3. Set `CLOUDFLARE_R2_PUBLIC_URL=https://images.knotsandperl.com` (no trailing slash).

All generated image URLs are built by `R2StorageService::url()`, which either passes
through a full `http(s)` URL or prefixes an R2 object key with this base URL. The
content file therefore stores only keys such as `products/pr001/main.webp`.

If a content field contains a full `https://` URL, the generator uses it as-is, but R2
keys are the supported path.
