# Content template

The content file has twelve sections. `resources/cms/template.json` lists the sample
rows and columns; the authoritative schema is `resources/cms/schema.json`.

Inspect the template with:

```bash
php artisan cms:template
```

Rules for editing outside the browser admin:

- Keep the section names and column names unchanged.
- One record per row with a unique `__row` number.
- IDs and slugs must be unique where the schema marks them unique.
- `status` is `active`, `inactive` or `draft`. Only `active` rows are generated.
- Use `|` to separate multiple image keys in `gallery_images` and `section_data`.
- After editing, run **Validate content** before publishing.

## Form delivery settings

The `Settings` section includes two `url`-typed keys for visitor forms:

- `form_endpoint` — public collector for the contact form.
- `newsletter_endpoint` — public collector for the newsletter form.

When left blank, the site keeps its existing placeholder message. When set, `site.js`
posts a JSON `POST` to the endpoint with the form fields, page slug and submission time;
the generator adds the endpoint's origin to the `connect-src` directive of the generated
`_headers` so the browser fetch is allowed. No secret is placed in generated JavaScript —
use a public collector you control.

- Use an **HTTPS** URL for a real collector.
- For local testing, `http` is accepted only for `localhost` or `127.0.0.1` with an
  explicit port (e.g. `http://localhost:8000`).
- The collector must allow cross-origin JSON POSTs, including the CORS `OPTIONS`
  preflight for `Content-Type: application/json`.
