# Updating content

All day-to-day edits happen in the browser admin. There is no spreadsheet to open
and no server for an editor to run.

## Sign in

Open the admin site (the Pages URL for `admin/`). Enter the password set when the
worker was deployed. The session lasts eight hours.

## The three screens

- **Pages** — page titles, text and pictures. Use **Add a page** for a new page.
- **Products** — product names, prices, descriptions and pictures. Use **Add a product**.
- **Settings** — brand name, tagline, logo, colours and contact details.

Every field is generated from `admin/schema.json`. Changing a label there changes
the form after a reload; no JavaScript change is needed.

## Changing a picture

Choose **Change picture** beside a picture and select a JPG, PNG, WEBP or GIF up to
8 MB. The picture uploads immediately and the preview updates. It becomes live only
after **Publish**.

## Adding a page or product

Open the relevant screen, choose **Add a page** or **Add a product**, fill in the
fields and click **Publish**. The admin generates the internal page name and keeps
the existing site design.

## Publish

Click **Publish** when the edits are ready. The admin saves the content, starts the
build and shows the current status. The generated site is then deployed to
Cloudflare Pages. Nothing goes live until this step.

## Download backup

Choose **Download backup** to save a copy of the current content file. Keep it
somewhere safe before large edits.

## Need a new field?

Ask the developer to add it to `admin/schema.json` and map it to the matching
content column. The form, preview and save logic will pick it up automatically.
