// Builds the live preview shown beside the editor. It uses the same class names as the site's templates
// and loads the site's own stylesheets, so the preview looks like the published page. It is built from the
// editor's current, unsaved data.
import { IMAGE_BASE_URL, SITE_URL } from './config.js';

const esc = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;');

const pictureUrl = (key) => {
  if (!key) return '';
  if (/^https?:\/\//i.test(key)) return key;
  return `${IMAGE_BASE_URL.replace(/\/$/, '')}/${String(key).replace(/^\//, '')}`;
};

const picture = (key, alt = '') => {
  const url = pictureUrl(key);
  return url ? `<img src="${esc(url)}" alt="${esc(alt)}" loading="lazy">` : '';
};

const visible = (items) => items.filter((item) => item.visible !== false);
const pick = (items, ids) => {
  const wanted = String(ids ?? '').split('|').map((value) => value.trim()).filter(Boolean);
  if (!wanted.length) return items;
  return wanted.map((id) => items.find((item) => String(item.id) === id)).filter(Boolean);
};

const open = (screen, id) => `data-open="${esc(screen)}:${esc(id)}"`;
const editing = (isEditing) => (isEditing ? ' is-editing' : '');

function categoryName(states, id) {
  return states.categories?.items.find((item) => String(item.id) === String(id))?.name ?? '';
}

function productCard(product, states, highlight = false) {
  const price = Number(product.sale_price) > 0 ? product.sale_price : product.price;
  return `<article class="product-card${editing(highlight)}" ${open('products', product.id)}>
    <a class="product-card-link" href="#">
      <div class="product-image">${picture(product.picture, product.name)}</div>
      <div class="product-info">
        <h3>${esc(product.name || 'Untitled')}</h3>
        <p class="product-category">${esc(categoryName(states, product.category))}</p>
        <p class="product-price">${price ? esc(price) : ''}</p>
      </div>
    </a>
  </article>`;
}

function paragraphs(text) {
  return String(text ?? '').split(/\n{2,}|\n/).filter((line) => line.trim())
    .map((line) => `<p>${esc(line)}</p>`).join('');
}

function sectionBlock(section, states, page) {
  const type = String(section.type ?? '');
  const items = String(section.items ?? '').split('|').map((value) => value.trim()).filter(Boolean);
  let body = '';
  switch (type) {
    case 'Hero':
      body = '<p class="hero-cta"><a class="button" href="#">Explore the collection</a></p>';
      break;
    case 'Featured Categories': {
      const categories = pick(visible(states.categories?.items ?? []), section.items);
      body = `<div class="card-grid categories-grid">${categories.map((category) => `
        <a class="category-card" href="#"><span>${esc(category.name)}</span>${picture(category.picture, category.name)}</a>`).join('')}</div>`;
      break;
    }
    case 'Featured Products':
    case 'New Arrivals':
    case 'Product Grid': {
      const products = pick(visible(states.products?.items ?? []), section.items).slice(0, 8);
      body = `<div class="card-grid product-grid">${products.map((product) => productCard(product, states)).join('')}</div>`;
      break;
    }
    case 'About Us': {
      const aboutPage = states.pages?.items.find((item) => item.slug === section.page) ?? page;
      body = `<div class="prose">${paragraphs(aboutPage?.text)}</div>`;
      break;
    }
    case 'Services':
      body = `<div class="card-grid services-grid">${visible(states.services?.items ?? []).map((service) => `
        <article class="service-card"><h3>${esc(service.title)}</h3><p>${esc(service.description)}</p></article>`).join('')}</div>`;
      break;
    case 'Testimonials':
      body = `<div class="card-grid testimonial-grid">${visible(states.testimonials?.items ?? []).map((item) => `
        <blockquote class="testimonial-card"><p>“${esc(item.text)}”</p>
        <footer>${esc(item.name)} <span>${'★'.repeat(Number(item.rating) || 0)}</span></footer></blockquote>`).join('')}</div>`;
      break;
    case 'Statistics':
      body = `<div class="stats-grid">${items.map((item) => {
        const [number = '', label = ''] = item.split(':').map((part) => part.trim());
        return `<div><strong>${esc(number)}</strong><span>${esc(label)}</span></div>`;
      }).join('')}</div>`;
      break;
    case 'Image Gallery':
      body = `<div class="gallery-grid">${items.map((key) => picture(key)).join('')}</div>`;
      break;
    case 'Promotional Banner':
      body = visible(states.banners?.items ?? []).map((banner) => `
        <a class="promo-banner" href="#" style="${banner.colour ? `background:${esc(banner.colour)};` : ''}min-height:120px;display:block">${picture(banner.desktop, banner.title) || '<span class="muted-note">Add a picture for this banner</span>'}
        <span class="promo-copy"><strong>${esc(banner.title)}</strong>${esc(banner.buttonText)}</span></a>`).join('');
      break;
    case 'Contact Information':
      body = `<div class="contact-details">${visible(states.contact?.items ?? []).map((item) => `
        <p><strong>${esc(item.label)}</strong> ${esc(item.value)}</p>`).join('')}</div>`;
      break;
    case 'Newsletter':
      body = '<form class="newsletter"><label>Email address</label><input type="email" placeholder="you@example.com"><button class="button" type="button">Join</button></form>';
      break;
    case 'Custom HTML':
      // Scripts are removed from the preview. The published site applies its own rules.
      body = `<div class="prose custom-html">${String(section.text ?? '').replace(/<script[\s\S]*?<\/script>/gi, '')}</div>`;
      break;
    default:
      body = '';
  }
  const style = [section.background ? `background:${section.background}` : '', section.textColour ? `color:${section.textColour}` : '']
    .filter(Boolean).join(';');
  const isEditing = Boolean(section.highlight);
  return `<section class="section section-${esc(type.toLowerCase().replace(/[^a-z0-9]+/g, '-'))}${editing(isEditing)}"
    ${open('sections', section.id)} ${style ? `style="${esc(style)}"` : ''}>
    <div class="section-inner">
      ${section.subtitle ? `<p class="eyebrow">${esc(section.subtitle)}</p>` : ''}
      ${section.title ? `<h2 class="section-title">${esc(section.title)}</h2>` : ''}
      ${section.text && type !== 'Custom HTML' ? `<p class="section-description">${esc(section.text)}</p>` : ''}
      ${body}
    </div>
  </section>`;
}

function pageSections(states, pageSlug, highlightId) {
  return (states.sections?.items ?? [])
    .filter((section) => section.page === pageSlug && section.visible !== false)
    .map((section) => ({ ...section, highlight: String(section.id) === String(highlightId) }));
}

function pageBody(states, page, highlightId) {
  const sections = pageSections(states, page.slug, highlightId);
  return `<header class="page-header"><h1>${esc(page.title || 'Untitled')}</h1></header>
    <div class="prose page-content">${paragraphs(page.text)}</div>
    ${sections.map((section) => sectionBlock(section, states, page)).join('')}`;
}

function nothingToShow(message) {
  return `<section class="section"><div class="section-inner"><p class="muted-note">${esc(message)}</p></div></section>`;
}

/** The main content for the item being edited. Returns null when a screen has no preview. */
export function previewBody(screenId, item, states) {
  if (!item) return nothingToShow('Add an item to see it here.');
  switch (screenId) {
    case 'pages':
      return pageBody(states, item, null);
    case 'sections': {
      const page = states.pages?.items.find((entry) => entry.slug === item.page);
      if (!page) return nothingToShow('Choose the page this block appears on to see it here.');
      return pageBody(states, page, item.id);
    }
    case 'products': {
      const products = visible(states.products?.items ?? []);
      const shown = products.some((entry) => String(entry.id) === String(item.id)) ? products : [...products, item];
      return `<header class="page-header"><h1>Collection</h1></header>
        <div class="card-grid product-grid">${shown.slice(0, 12).map((product) =>
          productCard(product, states, String(product.id) === String(item.id))).join('')}</div>`;
    }
    case 'categories':
      return `<header class="page-header"><h1>Categories</h1></header>
        <div class="card-grid categories-grid">${(states.categories?.items ?? []).map((category) => `
          <a class="category-card${editing(String(category.id) === String(item.id))}" ${open('categories', category.id)} href="#">
            <span>${esc(category.name || 'Untitled')}</span>${picture(category.picture, category.name)}</a>`).join('')}</div>`;
    case 'navigation': {
      const links = (states.navigation?.items ?? []).filter((link) => link.location === 'header' && link.visible !== false);
      return `<nav class="site-nav">${links.map((link) => `
        <a class="nav-link${editing(String(link.id) === String(item.id))}" ${open('navigation', link.id)} href="#">${esc(link.label || 'Link text')}</a>`).join('')}</nav>
        <p class="muted-note">Header links are shown above the page on the site.</p>`;
    }
    case 'banners':
      return visible(states.banners?.items ?? []).map((banner) => `
        <a class="promo-banner${editing(String(banner.id) === String(item.id))}" ${open('banners', banner.id)} href="#">
          ${picture(banner.desktop, banner.title)}<span class="promo-copy"><strong>${esc(banner.title)}</strong>${esc(banner.buttonText)}</span></a>`).join('')
        || nothingToShow('No visible banners yet.');
    case 'testimonials':
      return `<div class="card-grid testimonial-grid">${visible(states.testimonials?.items ?? []).map((testimonial) => `
        <blockquote class="testimonial-card${editing(String(testimonial.id) === String(item.id))}" ${open('testimonials', testimonial.id)}>
          <p>“${esc(testimonial.text)}”</p><footer>${esc(testimonial.name)}</footer></blockquote>`).join('')}</div>`;
    case 'services':
      return `<div class="card-grid services-grid">${visible(states.services?.items ?? []).map((service) => `
        <article class="service-card${editing(String(service.id) === String(item.id))}" ${open('services', service.id)}>
          <h3>${esc(service.title)}</h3><p>${esc(service.description)}</p></article>`).join('')}</div>`;
    case 'contact':
      return `<div class="contact-details">${visible(states.contact?.items ?? []).map((detail) => `
        <p class="${editing(String(detail.id) === String(item.id)).trim()}" ${open('contact', detail.id)}>
        <strong>${esc(detail.label)}</strong> ${esc(detail.value)}</p>`).join('')}</div>`;
    default:
      return null;
  }
}

/** A complete HTML document for the preview frame. Clicks on a block tell the editor which item to open. */
export function previewDocument(screenId, item, states) {
  const body = previewBody(screenId, item, states);
  if (body === null) return null;
  return `<!doctype html><html lang="en"><head><meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;500;600&family=Montserrat:wght@300;400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="${SITE_URL}/css/site.css">
    <link rel="stylesheet" href="${SITE_URL}/css/theme.css">
    <style>
      body { margin: 0; padding: 24px 20px; background: #fff; }
      [data-open] { cursor: pointer; }
      [data-open]:hover { outline: 1px dashed #b89aa1; outline-offset: 4px; }
      .is-editing { outline: 2px solid #7b2d3e !important; outline-offset: 4px; }
      .muted-note { color: #736b61; font-size: 14px; }
      .nav-link { margin-right: 14px; }
    </style></head>
    <body>${body}
    <script>
      document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-open]');
        if (!target) return;
        event.preventDefault();
        parent.postMessage({ type: 'open-item', target: target.getAttribute('data-open') }, '*');
      });
      document.addEventListener('submit', function (event) { event.preventDefault(); });
    </script></body></html>`;
}
