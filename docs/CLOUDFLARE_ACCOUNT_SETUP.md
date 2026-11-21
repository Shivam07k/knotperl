# 6. Cloudflare account setup

1. Create a Cloudflare account and verify your email.
2. Note the **Account ID** from the right sidebar of the Cloudflare dashboard. Put it in
   `CLOUDFLARE_ACCOUNT_ID`.
3. Create an API token: **My Profile → API Tokens → Create Token**.
   - Template: **Edit Cloudflare Workers** is too broad; instead create a custom token with
     `Account → Pages → Edit` and `Account → Account Settings → Read`.
   - Put it in `CLOUDFLARE_API_TOKEN`.
4. If you prefer GitHub Actions over direct upload, create a repository secret with the
   same token (see [GITHUB_SETUP.md](GITHUB_SETUP.md)).

The API token is only used server-side by `PagesDeploymentService` and by Wrangler in
GitHub Actions. It is never written to the static output.

Least privilege: a token that can only edit Pages is sufficient; R2 uses separate
S3-compatible keys.
