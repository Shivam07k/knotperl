# 7. R2 bucket creation

1. Cloudflare dashboard → **R2 Object Storage** → **Create bucket**, e.g.
   `knots-and-perl-assets`.
2. **Manage R2 API tokens → Create API token** with `Object Read & Write` scoped to that
   bucket. Copy the Access Key ID and Secret Access Key into `.env`:

```dotenv
CLOUDFLARE_R2_ACCESS_KEY_ID=...
CLOUDFLARE_R2_SECRET_ACCESS_KEY=...
CLOUDFLARE_R2_BUCKET=knots-and-perl-assets
CLOUDFLARE_ACCOUNT_ID=...
CLOUDFLARE_R2_PUBLIC_URL=https://images.knotsandperl.com
```

The endpoint is derived as `https://{CLOUDFLARE_ACCOUNT_ID}.r2.cloudflarestorage.com`.

Object layout:

```
assets/logo/        products/{product_id}/   categories/{category_id}/
services/{service_id}/   testimonials/   pages/   assets/banners/
```

Sidecars live under `.hashes/` and `.meta/` and hold the sha256 index and alt-text
metadata. The dashboard's **Images** page uploads, searches, replaces and queues deletions.

Test the connection from the dashboard overview; it shows `connected` when `headBucket`
succeeds.
