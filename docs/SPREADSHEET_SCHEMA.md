# Content schema reference

Full column reference for the 12 tabs. The machine-readable version is
`resources/cms/schema.json`; the importable template is `resources/cms/template.json`.

| Tab | Primary key | Columns |
|---|---|---|
| Settings | key | key, value, type, description, updated_at |
| Pages | page_id | page_id, title, slug, page_type, template, content, featured_image, meta_title, meta_description, meta_keywords, status, sort_order, updated_at |
| Products | product_id | product_id, product_name, slug, category_id, short_description, full_description, regular_price, sale_price, SKU, primary_image, gallery_images, featured, status, sort_order, meta_title, meta_description, created_at, updated_at |
| Categories | category_id | category_id, category_name, slug, description, category_image, parent_category_id, status, sort_order, meta_title, meta_description |
| Banners | banner_id | banner_id, title, subtitle, description, desktop_image, mobile_image, button_text, button_url, background_color, status, sort_order, start_date, end_date |
| Sections | section_id | section_id, page_slug, section_type, section_title, section_subtitle, section_description, section_data, background_color, text_color, status, sort_order |
| Navigation | menu_id | menu_id, menu_location, label, url, parent_menu_id, open_new_tab, status, sort_order |
| Testimonials | testimonial_id | testimonial_id, customer_name, customer_designation, customer_image, testimonial_text, rating, status, sort_order |
| Services | service_id | service_id, title, slug, description, icon, image, status, sort_order |
| SEO | page_slug | page_slug, meta_title, meta_description, meta_keywords, canonical_url, og_title, og_description, og_image, robots_index, robots_follow |
| Contact | contact_id | contact_id, label, value, type, icon, status, sort_order |
| Redirects | redirect_id | redirect_id, source_url, destination_url, redirect_type, status |

## Enums

- `status`: `active`, `inactive`, `draft`.
- `Pages.template`: `home`, `about`, `contact`, `services`, `products`, `category`, `product`, `custom`.
- `Sections.section_type`: Hero, Featured Categories, Featured Products, New Arrivals,
  Product Grid, About Us, Services, Testimonials, Statistics, Image Gallery,
  Promotional Banner, Contact Information, Newsletter, Custom HTML.
- `Navigation.menu_location`: `header`, `footer`.
- `Contact.type`: `email`, `phone`, `whatsapp`, `address`, `map`, `social`.
- `Redirects.redirect_type`: `301`, `302`.
- `Testimonials.rating`: 1–5.

## Relationships

- `Products.category_id` → `Categories.category_id`.
- `Categories.parent_category_id` → `Categories.category_id` (blank = root).
- `Navigation.parent_menu_id` → `Navigation.menu_id` (blank = top level).
- `Sections.page_slug` → `Pages.slug` (`home` = homepage).
- `SEO.page_slug` → `Pages.slug` (`home` = homepage).

## Brand settings (Settings tab)

`theme` (`obsidian` or `pearl`), `enable_intro`, `enable_intro_sound` (opt-in WebAudio
cue), `countdown_seconds` (0–10), `enable_heavy_animation`, `intro_video_url`,
`intro_texture_url`, `primary_color`,
`secondary_color`, `accent_color`, `default_font`, `site_logo`, `favicon`,
`site_name`, `site_tagline`, `contact_email`, `contact_phone`, `whatsapp_number`,
`business_address`, `copyright_text`, `default_language`, `currency`, `timezone`,
`maintenance_mode`.

Unknown setting keys are ignored, so adding private notes to the sheet cannot leak into
the generated JSON (only a fixed public allowlist is exported).
