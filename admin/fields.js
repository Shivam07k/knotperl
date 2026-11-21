function screenFor(schema, screenId) {
  const screen = schema.screens.find((item) => item.id === screenId);
  if (!screen) throw new Error(`Unknown screen: ${screenId}`);
  return screen;
}

export function screens(schema) {
  return schema.screens;
}

export function labelFor(schema, screenId, key) {
  const field = screenFor(schema, screenId).fields.find((item) => item.key === key);
  return field?.label ?? key;
}

function column(field) {
  return field.column ?? field.key;
}

export function slugify(value) {
  return String(value).toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

const today = () => new Date().toISOString().slice(0, 10);

const plural = (count) => (count === 1 ? '' : 's');

function rowId(screen, row) {
  return String(row[screen.idField] ?? row.__row);
}

/** True when a stored value (for example "active" or "true") counts as ticked. */
export function isChecked(field, value) {
  return String(value ?? '').trim().toLowerCase() === String(field.onValue ?? 'true').toLowerCase();
}

function storedValue(field, value) {
  if (field.type === 'checkbox') {
    return value ? String(field.onValue ?? 'true') : String(field.offValue ?? 'false');
  }
  return value ?? '';
}

export function fromContentJson(schema, screenId, content) {
  const screen = screenFor(schema, screenId);
  const rows = content[screen.tab] ?? [];
  if (screen.layout === 'key-value') {
    const values = {};
    for (const field of screen.fields) {
      values[field.key] = rows.find((row) => row.key === field.key)?.value ?? '';
    }
    return { values };
  }

  const items = rows.map((row) => {
    const item = { id: rowId(screen, row), __row: row.__row, slug: row.slug ?? '' };
    for (const field of screen.fields) {
      const raw = row[column(field)] ?? '';
      item[field.key] = field.type === 'checkbox' ? isChecked(field, raw) : raw;
    }
    return item;
  });
  return { items };
}

export function makeBlankItem(schema, screenId) {
  const screen = screenFor(schema, screenId);
  const item = { id: '', slug: '' };
  for (const field of screen.fields) item[field.key] = field.default ?? '';
  return item;
}

/** A new id that no saved row and no unsaved item already uses. */
export function nextId(schema, screenId, state, content) {
  const screen = screenFor(schema, screenId);
  const used = new Set([
    ...state.items.map((item) => String(item.id)),
    ...(content[screen.tab] ?? []).map((row) => rowId(screen, row)),
  ]);
  let number = used.size + 1;
  let id;
  do {
    id = `${screen.idPrefix}${String(number++).padStart(3, '0')}`;
  } while (used.has(id));
  return id;
}

/** Choices for a select field, taken from the editor's current items so unsaved rows appear too. */
export function optionsFor(states, field, itemId) {
  const options = [];
  if (field.emptyLabel !== undefined) options.push({ value: '', label: field.emptyLabel });
  if (field.options) return [...options, ...field.options];
  if (field.optionsFrom) {
    for (const item of states[field.optionsFrom]?.items ?? []) {
      if (!item.id) continue;
      if (field.excludeSelf && String(item.id) === String(itemId)) continue;
      options.push({ value: String(item.id), label: String(item[field.labelKey] || item.id) });
    }
  }
  return options;
}

/** Page, category and product addresses for the menu "Goes to" picker. */
export function linkOptions(states) {
  const links = [];
  const hidden = (item) => (item.visible ? '' : ' (hidden)');

  for (const item of states.pages?.items ?? []) {
    const slug = item.slug || slugify(item.title ?? '');
    const value = slug === '' || slug === 'home' ? '/' : `/${slug}/`;
    links.push({ group: 'Pages', label: `${item.title || 'Untitled'}${hidden(item)}`, value });
  }
  for (const item of states.categories?.items ?? []) {
    const slug = item.slug || slugify(item.name ?? '');
    links.push({ group: 'Categories', label: `${item.name || 'Untitled'}${hidden(item)}`, value: `/category/${slug}/` });
  }
  for (const item of states.products?.items ?? []) {
    const slug = item.slug || slugify(item.name ?? '');
    links.push({ group: 'Products', label: `${item.name || 'Untitled'}${hidden(item)}`, value: `/products/${slug}/` });
  }
  links.push({ group: 'Collection', label: 'All products', value: '/products/' });
  return links;
}

/** Reasons an item cannot be deleted yet. An empty list means it can be deleted. */
export function blockersFor(states, screenId, item) {
  const id = String(item.id ?? '');
  if (!id) return [];
  const problems = [];

  if (screenId === 'categories') {
    const products = (states.products?.items ?? []).filter((product) => product.category === id).length;
    if (products) {
      problems.push(`${products} product${plural(products)} still ${products === 1 ? 'uses' : 'use'} this category. Move ${products === 1 ? 'it' : 'them'} to another category first.`);
    }
    const children = (states.categories?.items ?? []).filter((category) => category.parent === id).length;
    if (children) {
      problems.push(`${children} categor${children === 1 ? 'y sits' : 'ies sit'} inside this one. Move ${children === 1 ? 'it' : 'them'} first.`);
    }
  }

  if (screenId === 'navigation') {
    const children = (states.navigation?.items ?? []).filter((link) => link.parent === id).length;
    if (children) {
      problems.push(`${children} menu link${plural(children)} sit inside this one. Move ${children === 1 ? 'it' : 'them'} first.`);
    }
  }

  return problems;
}

function newRow(screen, item, rowNumber, position, id) {
  const row = {
    __row: rowNumber,
    [screen.idField]: id,
    status: 'active',
    sort_order: String(position),
    updated_at: today(),
  };
  if (screen.slugFrom) row.slug = slugify(item[screen.slugFrom] ?? '');
  if (screen.tab === 'Pages') {
    row.page_type = 'content';
    row.template = 'custom';
  }
  if (screen.tab === 'Products') {
    row.regular_price = '0';
    row.sale_price = '0';
    row.gallery_images = '';
  }
  return row;
}

export function toContentJson(schema, screenId, state, content) {
  const screen = screenFor(schema, screenId);
  const rows = [...(content[screen.tab] ?? [])];

  if (screen.layout === 'key-value') {
    for (const field of screen.fields) {
      const value = state.values[field.key] ?? '';
      const row = rows.find((item) => item.key === field.key);
      if (row) {
        row.value = value;
      } else {
        rows.push({
          __row: Math.max(0, ...rows.map((item) => Number(item.__row) || 0)) + 1,
          key: field.key,
          value,
          type: 'text',
          description: '',
          updated_at: today(),
        });
      }
    }
    return { ...content, [screen.tab]: rows };
  }

  // Only rows still present in the editor are kept, so removing an item deletes it.
  const usedIds = new Set(rows.map((row) => rowId(screen, row)));
  let nextRow = Math.max(0, ...rows.map((row) => Number(row.__row) || 0)) + 1;
  let position = rows.length + 1;
  const result = [];

  for (const item of state.items) {
    const existing = item.id ? rows.find((row) => rowId(screen, row) === String(item.id)) : undefined;
    let row;
    if (existing) {
      row = { ...existing };
    } else {
      let id = String(item.id || '');
      while (!id || usedIds.has(id)) {
        id = `${screen.idPrefix}${String(position++).padStart(3, '0')}`;
      }
      usedIds.add(id);
      if (screen.slugFrom && !item.slug) item.slug = slugify(item[screen.slugFrom] ?? '');
      row = newRow(screen, item, nextRow++, position, id);
      item.id = id;
      item.__row = row.__row;
    }
    for (const field of screen.fields) row[column(field)] = storedValue(field, item[field.key]);
    result.push(row);
  }

  if (screen.sortable) {
    result.forEach((row, index) => { row.sort_order = String(index + 1); });
  }
  return { ...content, [screen.tab]: result };
}

/** The public address of each kind of page, built from its web address. */
export const ADDRESS = {
  pages: (slug) => (slug === '' || slug === 'home' ? '/' : `/${slug}/`),
  products: (slug) => `/products/${slug}/`,
  categories: (slug) => `/category/${slug}/`,
};

const ID_FIELD = { pages: 'page_id', products: 'product_id', categories: 'category_id' };
const TAB_FOR = { pages: 'Pages', products: 'Products', categories: 'Categories' };
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** Makes typed web addresses tidy, for example "Pearl Drop!" becomes "pearl-drop". */
export function normalizeSlugs(schema, states) {
  for (const screenId of Object.keys(ADDRESS)) {
    for (const item of states[screenId]?.items ?? []) {
      if (item.slug) item.slug = slugify(item.slug);
    }
  }
}

/** Problems that stop a save: missing or duplicate web addresses. */
export function slugProblems(schema, states) {
  const problems = [];
  for (const screenId of Object.keys(ADDRESS)) {
    const screen = screenFor(schema, screenId);
    const seen = new Set();
    for (const item of states[screenId]?.items ?? []) {
      const name = item[screen.titleFrom] || 'Untitled';
      const slug = item.slug || slugify(item[screen.titleFrom] ?? '');
      if (!slug) {
        problems.push(`"${name}" needs a web address. Type one in the Web address box.`);
        continue;
      }
      if (!SLUG_PATTERN.test(slug)) {
        problems.push(`The web address "${slug}" can only use lowercase letters, numbers and dashes.`);
      }
      if (seen.has(slug)) {
        problems.push(`Two ${screen.itemLabel}s use the web address "${slug}". Give one of them a different address.`);
      }
      seen.add(slug);
    }
  }
  return problems;
}

/**
 * Redirects needed because a saved web address changed. Call this before the content is updated,
 * so the old addresses are still in the saved rows.
 */
export function redirectsForSlugChanges(schema, states, content) {
  const changes = [];
  for (const screenId of Object.keys(ADDRESS)) {
    const saved = new Map((content[TAB_FOR[screenId]] ?? []).map((row) => [
      String(row[ID_FIELD[screenId]]), row.slug,
    ]));
    for (const item of states[screenId]?.items ?? []) {
      const before = saved.get(String(item.id));
      const after = item.slug;
      if (!before || !after || before === after || before === 'home') continue;
      changes.push({ source: ADDRESS[screenId](before), destination: ADDRESS[screenId](after) });
    }
  }
  return changes;
}

/** Adds redirect rows. A redirect that pointed at a changed address is moved on to the new address. */
export function applyRedirects(content, changes) {
  const updated = (content.Redirects ?? []).map((row) => ({ ...row }));
  let number = Math.max(0, ...updated.map((row) => Number(String(row.redirect_id).replace(/\D/g, '')) || 0)) + 1;
  let rowNumber = Math.max(0, ...updated.map((row) => Number(row.__row) || 0)) + 1;

  for (const change of changes) {
    if (change.source === change.destination) continue;
    for (const row of updated) {
      if (row.destination_url === change.source) row.destination_url = change.destination;
    }
    const existing = updated.find((row) => row.source_url === change.source);
    if (existing) {
      existing.destination_url = change.destination;
      existing.status = 'active';
      continue;
    }
    updated.push({
      __row: rowNumber++,
      redirect_id: `rd${String(number++).padStart(3, '0')}`,
      source_url: change.source,
      destination_url: change.destination,
      redirect_type: '301',
      status: 'active',
    });
  }
  return { ...content, Redirects: updated };
}