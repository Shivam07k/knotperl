# First website publishing

1. Complete [Cloudflare setup](CLOUDFLARE_SETUP.md).
2. Import the current content once with `php artisan cms:content:import --out=storage/app/content.json`
   and upload it to R2 as `content.json`.
3. Sign in to the browser admin and open **Validation** in the publishing dashboard.
   Fix any errors; warnings are allowed.
4. Use **Preview & download** if you want to inspect the generated ZIP first.
5. Click **Publish** in the browser admin. The workflow validates the content, renders
   the site and deploys it to Cloudflare Pages.
6. Check the live URL, `robots.txt`, `sitemap.xml`, a product page and a category page.

If deployment fails, the admin status shows the failure and the previous live build
stays untouched. See [Troubleshooting](TROUBLESHOOTING.md).
