import { IMAGE_BASE_URL, PREVIEW_URL, WORKER_URL } from './config.js';
import { previewDocument } from './preview.js';
import {
  applyRedirects, blockersFor, fromContentJson, linkOptions, makeBlankItem, nextId, normalizeSlugs, optionsFor,
  redirectsForSlugChanges, screens, slugProblems, toContentJson,
} from './fields.js';

const el = (id) => document.getElementById(id);
const IMAGE_TYPES = 'image/jpeg,image/png,image/webp,image/gif';
const HISTORY = '__history';
const MEDIA = '__media';
const NAV_GROUPS = [
  { title: 'Content', ids: ['pages', 'sections', 'products', 'categories', 'navigation', 'redirects'] },
  { title: 'Library', ids: [MEDIA] },
  { title: 'Site', ids: ['banners', 'testimonials', 'services', 'contact', 'settings'] },
  { title: 'Activity', ids: [HISTORY] },
];
const ROLE_NAMES = { admin: 'administrator', viewer: 'view-only account' };
const BUSY = ['publishing', 'previewing'];

let schema;
let content;
let contentVersion = '';
let role = 'viewer';
let screenId;
let dirty = false;
let selected = new Set();
let selectedFor = null;
let listQuery = '';
let listFilter = 'all';
const openIds = {};
const states = {};
let fieldCounter = 0;

async function request(path, options = {}) {
  const response = await fetch(WORKER_URL + path, { credentials: 'include', ...options });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(body.error || 'Something went wrong.');
    error.status = response.status;
    throw error;
  }
  return { body, headers: response.headers };
}

async function api(path, options) {
  return (await request(path, options)).body;
}

/* ---------- feedback ---------- */

function setStatus(message, isError = false) {
  const status = el('status');
  status.textContent = message;
  status.classList.toggle('error', isError);
}

function toast(message, isError = false) {
  const node = document.createElement('div');
  node.className = `toast${isError ? ' error' : ''}`;
  node.setAttribute('role', isError ? 'alert' : 'status');
  node.textContent = message;
  const stack = el('toasts');
  stack.append(node);
  while (stack.children.length > 3) stack.firstElementChild.remove();
  setTimeout(() => node.remove(), isError ? 7000 : 3500);
}

function updateButtons() {
  const admin = role === 'admin';
  el('save').hidden = !admin;
  el('preview').hidden = !admin;
  el('publish').hidden = !admin;
  el('save').disabled = !dirty;
}

function markDirty() {
  dirty = true;
  setStatus('Unsaved changes. Save, then publish when you are ready.');
  updateButtons();
}

/* ---------- session ---------- */

function showSignIn(message = '') {
  el('login').hidden = false;
  el('app').hidden = true;
  el('login-error').textContent = message;
}

async function signIn() {
  try {
    await api('/api/login', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ password: el('password').value }),
    });
  } catch (error) {
    el('login-error').textContent = error.message;
    return;
  }
  el('password').value = '';
  await start().catch((error) => {
    console.error(error);
    setStatus(`Something went wrong: ${error.message}`, true);
  });
}

async function signOut() {
  const warning = dirty ? '\n\nUnsaved changes will be lost.' : '';
  if (!confirm(`Log out? This signs out every device, not only this one.${warning}`)) return;
  try {
    await api('/api/logout', { method: 'POST' });
  } catch {
    // The session may already be gone. Showing the sign-in form is still correct.
  }
  dirty = false;
  showSignIn();
}

async function start() {
  if (!schema) schema = await (await fetch('./schema.json')).json();
  let loaded;
  try {
    loaded = await request('/api/content');
  } catch (error) {
    showSignIn(error.status === 401 ? '' : error.message);
    return;
  }
  content = loaded.body;
  contentVersion = loaded.headers.get('x-content-version') ?? '';
  dirty = false;
  try {
    role = (await api('/api/session')).role;
  } catch {
    role = 'viewer';
  }
  el('role').hidden = role === 'admin';
  for (const screen of screens(schema)) states[screen.id] = fromContentJson(schema, screen.id, content);
  el('login').hidden = true;
  el('app').hidden = false;
  screenId = screenId ?? screens(schema)[0].id;
  renderNav();
  renderScreen();
  refreshStatus();
}

/* ---------- navigation ---------- */

function screenName(id) {
  if (id === MEDIA) return 'Media';
  if (id === HISTORY) return 'History';
  return screens(schema).find((screen) => screen.id === id)?.label ?? '';
}

function renderNav() {
  const nav = el('screens');
  nav.replaceChildren();
  for (const group of NAV_GROUPS) {
    const heading = document.createElement('p');
    heading.className = 'nav-group';
    heading.textContent = group.title;
    nav.append(heading);
    for (const id of group.ids) {
      const button = document.createElement('button');
      button.className = 'nav-item';
      button.textContent = screenName(id);
      button.classList.toggle('active', id === screenId);
      if (id === screenId) button.setAttribute('aria-current', 'page');
      button.onclick = () => { screenId = id; selected = new Set(); renderNav(); renderScreen(); };
      nav.append(button);
    }
  }
}

/* ---------- field helpers ---------- */

function previewUrl(value) {
  if (!value) return null;
  if (/^https?:\/\//i.test(value)) return value;
  return `${IMAGE_BASE_URL.replace(/\/$/, '')}/${String(value).replace(/^\//, '')}`;
}

/** Converts a stored UTC time into the value a datetime-local input expects, in the viewer's timezone. */
function toLocalInput(iso) {
  if (!iso) return '';
  const date = new Date(iso);
  if (Number.isNaN(date.getTime())) return '';
  return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function actionButton(text, title, disabled, onClick, danger = false) {
  const button = document.createElement('button');
  button.type = 'button';
  button.textContent = text;
  button.title = title;
  button.disabled = disabled;
  if (danger) button.classList.add('danger');
  button.onclick = onClick;
  return button;
}

function hint(text) {
  const paragraph = document.createElement('p');
  paragraph.className = 'hint';
  paragraph.textContent = text;
  return paragraph;
}

function heading(tag, text, className = '') {
  const node = document.createElement(tag);
  node.textContent = text;
  if (className) node.className = className;
  return node;
}

function nextFieldId() {
  fieldCounter += 1;
  return `field-${fieldCounter}`;
}

function galleryControl(value, change) {
  const keys = String(value ?? '').split('|').map((key) => key.trim()).filter(Boolean);
  const box = document.createElement('div');
  box.className = 'gallery';

  const save = () => { change(keys.join('|')); draw(); };

  function draw() {
    box.replaceChildren();
    const list = document.createElement('ol');
    list.className = 'thumbs';
    list.setAttribute('aria-label', 'Gallery pictures in order');
    keys.forEach((key, index) => {
      const card = document.createElement('li');
      card.className = 'thumb';
      const image = document.createElement('img');
      image.alt = '';
      image.src = previewUrl(key) ?? '';
      const actions = document.createElement('div');
      actions.className = 'thumb-actions';
      actions.append(
        actionButton('Earlier', 'Move this picture earlier', index === 0, () => {
          [keys[index - 1], keys[index]] = [keys[index], keys[index - 1]];
          save();
        }),
        actionButton('Later', 'Move this picture later', index === keys.length - 1, () => {
          [keys[index], keys[index + 1]] = [keys[index + 1], keys[index]];
          save();
        }),
        actionButton('Remove', 'Remove this picture from the gallery', false, () => {
          keys.splice(index, 1);
          save();
        }, true),
      );
      card.append(image, actions);
      list.append(card);
    });
    box.append(list);

    const picker = document.createElement('input');
    picker.type = 'file';
    picker.multiple = true;
    picker.accept = IMAGE_TYPES;
    picker.hidden = true;
    picker.onchange = async () => {
      let added = 0;
      for (const file of [...(picker.files ?? [])]) {
        const form = new FormData();
        form.append('file', file);
        try {
          const result = await api('/api/image', { method: 'POST', body: form });
          keys.push(result.key);
          added += 1;
        } catch (error) {
          toast(error.message, true);
          break;
        }
      }
      if (added) {
        save();
        toast(`${added} picture${added === 1 ? '' : 's'} added to the gallery.`);
      }
    };
    const addLabel = document.createElement('label');
    addLabel.className = 'file-button';
    addLabel.textContent = 'Add pictures';
    addLabel.append(picker);
    box.append(addLabel);
  }

  draw();
  return box;
}

function makeField(field, value, onChange, itemId) {
  const change = (next) => {
    onChange(next);
    markDirty();
    // Some fields change which other fields apply, such as a section's type.
    if (field.rerender) renderScreen();
  };
  const wrapper = document.createElement('div');
  wrapper.className = 'field';
  const id = nextFieldId();

  if (field.type === 'checkbox') {
    const label = document.createElement('label');
    label.className = 'switch';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.id = id;
    input.checked = Boolean(value);
    input.onchange = () => change(input.checked);
    const text = document.createElement('span');
    text.textContent = field.label;
    label.append(input, text);
    wrapper.append(label);
    if (field.help) wrapper.append(hint(field.help));
    return wrapper;
  }

  const label = document.createElement('label');
  label.textContent = field.label;
  label.htmlFor = id;
  wrapper.append(label);

  if (field.type === 'image') {
    const node = el('image-field').content.cloneNode(true);
    const image = node.querySelector('img');
    const source = previewUrl(value);
    if (source) { image.src = source; image.hidden = false; }
    image.alt = '';
    const picker = node.querySelector('input');
    picker.id = id;
    picker.onchange = async () => {
      if (!picker.files?.[0]) return;
      const form = new FormData();
      form.append('file', picker.files[0]);
      try {
        const result = await api('/api/image', { method: 'POST', body: form });
        change(result.key);
        if (result.url) { image.src = result.url; image.hidden = false; }
        toast('Picture uploaded. Save, then publish to put it on the website.');
      } catch (error) {
        toast(error.message, true);
      }
    };
    wrapper.append(node);
    return wrapper;
  }

  if (field.type === 'datetime') {
    const input = document.createElement('input');
    input.type = 'datetime-local';
    input.id = id;
    input.value = toLocalInput(value);
    input.setAttribute('aria-describedby', `${id}-help`);
    input.onchange = () => change(input.value ? new Date(input.value).toISOString() : '');
    const clear = actionButton('Clear', 'Remove the hide time', !value, () => change(''));
    clear.classList.add('ghost');
    wrapper.append(input, clear);
    if (field.help) {
      const help = hint(field.help);
      help.id = `${id}-help`;
      wrapper.append(help);
    }
    return wrapper;
  }

  if (field.type === 'gallery') {
    wrapper.append(galleryControl(value, change));
    return wrapper;
  }

  if (field.type === 'select') {
    const select = document.createElement('select');
    select.id = id;
    const options = optionsFor(states, field, itemId);
    for (const option of options) select.append(new Option(option.label, option.value));
    if (value && !options.some((option) => option.value === String(value))) {
      select.append(new Option(`${value} (missing)`, value));
    }
    select.value = String(value ?? '');
    select.onchange = () => change(select.value);
    wrapper.append(select);
  } else if (field.type === 'link') {
    const input = document.createElement('input');
    input.type = 'text';
    input.id = id;
    input.value = value ?? '';
    input.placeholder = 'Type an address, or pick one below';
    input.oninput = () => change(input.value);

    const picker = document.createElement('select');
    picker.setAttribute('aria-label', `Pick a page for ${field.label}`);
    picker.append(new Option('Pick a page, category or product...', ''));
    const groups = new Map();
    for (const link of linkOptions(states)) {
      if (!groups.has(link.group)) {
        const group = document.createElement('optgroup');
        group.label = link.group;
        groups.set(link.group, group);
        picker.append(group);
      }
      groups.get(link.group).append(new Option(link.label, link.value));
    }
    picker.onchange = () => {
      if (!picker.value) return;
      input.value = picker.value;
      change(picker.value);
      picker.value = '';
    };
    wrapper.append(input, picker);
  } else {
    if (field.help) wrapper.append(hint(field.help));
    const input = document.createElement(field.type === 'textarea' ? 'textarea' : 'input');
    input.id = id;
    if (input.tagName === 'INPUT') input.type = ['color', 'email', 'tel', 'date'].includes(field.type) ? field.type : 'text';
    if (field.type === 'color') {
      // An empty colour means "use the site's default". Show white and offer a way to clear it.
      input.value = value || '#ffffff';
      input.oninput = () => change(input.value);
      const row = document.createElement('div');
      row.className = 'colour-row';
      const clear = actionButton('Use default', 'Remove the colour and use the site default', !value, () => change(''));
      clear.classList.add('ghost');
      row.append(input, clear);
      wrapper.append(row);
      return wrapper;
    }
    input.value = value ?? '';
    input.oninput = () => change(input.value);
    wrapper.append(input);
    return wrapper;
  }
  if (field.help) wrapper.append(hint(field.help));
  return wrapper;
}

/** Items a section shows. What is offered depends on the block type, so this control is built per type. */
const PRODUCT_BLOCKS = ['Featured Products', 'New Arrivals', 'Product Grid'];

function plainLabel(text) {
  const label = document.createElement('label');
  label.textContent = text;
  return label;
}

function pickList(options, raw, change) {
  const picked = new Set(String(raw ?? '').split('|').map((value) => value.trim()).filter(Boolean));
  const list = document.createElement('div');
  list.className = 'pick-list';
  const choices = options.filter((option) => option.value);
  if (!choices.length) list.append(hint('Nothing to pick yet. Add some first.'));
  for (const option of choices) {
    const row = document.createElement('label');
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = picked.has(option.value);
    input.onchange = () => {
      if (input.checked) picked.add(option.value);
      else picked.delete(option.value);
      change([...picked].join('|'));
    };
    row.append(input, document.createTextNode(` ${option.label}`));
    list.append(row);
  }
  return list;
}

function linesControl(raw, change) {
  const area = document.createElement('textarea');
  area.value = String(raw ?? '').split('|').join('\n');
  area.oninput = () => change(area.value.split('\n').map((line) => line.trim()).filter(Boolean).join('|'));
  return area;
}

function itemsControl(type, raw, change) {
  const wrap = document.createElement('div');
  wrap.className = 'field';
  if (type === 'Featured Categories') {
    wrap.append(plainLabel('Categories to show'));
    wrap.append(pickList(optionsFor(states, { optionsFrom: 'categories', labelKey: 'name' }, ''), raw, change));
    wrap.append(hint('Leave all unticked to show every category.'));
    return wrap;
  }
  if (PRODUCT_BLOCKS.includes(type)) {
    wrap.append(plainLabel('Products to show'));
    wrap.append(pickList(optionsFor(states, { optionsFrom: 'products', labelKey: 'name' }, ''), raw, change));
    wrap.append(hint('Leave all unticked to show every product.'));
    return wrap;
  }
  if (type === 'Image Gallery') {
    wrap.append(plainLabel('Pictures'));
    wrap.append(galleryControl(raw, change));
    return wrap;
  }
  if (type === 'Statistics') {
    wrap.append(plainLabel('Numbers and labels'));
    wrap.append(linesControl(raw, change));
    wrap.append(hint('One per line, as Number : Label, for example 500+ : Pieces made by hand.'));
    return wrap;
  }
  return null;
}

/** Groups fields under plain headings, in the order the schema lists them. */
function groupedFields(screen, values, onChange, itemId) {
  const groups = new Map();
  for (const field of screen.fields) {
    const name = field.group ?? 'Details';
    if (!groups.has(name)) groups.set(name, []);
    const node = field.type === 'items'
      ? itemsControl(values.type, values[field.key], (value) => { onChange(field.key, value); markDirty(); })
      : makeField(field, values[field.key], (value) => onChange(field.key, value), itemId);
    if (node) groups.get(name).push(node);
  }
  const wrapper = document.createDocumentFragment();
  for (const [name, nodes] of groups) {
    const section = document.createElement('section');
    section.className = 'group';
    section.append(heading('h3', name));
    section.append(...nodes);
    wrapper.append(section);
  }
  return wrapper;
}

/* ---------- helpers ---------- */

function categoryName(id) {
  return states.categories?.items.find((item) => String(item.id) === String(id))?.name ?? '';
}

/* ---------- collection screens (list and editor) ---------- */

function rowSummary(screen, item) {
  switch (screen.id) {
    case 'products': {
      const price = Number(item.sale_price) > 0 ? item.sale_price : item.price;
      return [categoryName(item.category) || 'No category', price ? `Price ${price}` : ''].filter(Boolean).join(' · ');
    }
    case 'pages': return item.slug ? `/${item.slug}/` : '/';
    case 'categories': return item.parent ? `Inside ${categoryName(item.parent)}` : 'Top level';
    case 'navigation': return `${item.location === 'footer' ? 'Footer' : 'Top menu'} · ${item.link || 'No link yet'}`;
    case 'redirects': return `${item.source || '?'} goes to ${item.destination || '?'}`;
    case 'sections': {
      const page = states.pages?.items.find((entry) => entry.slug === item.page);
      return page ? `On ${page.title || 'Untitled'}` : 'Not on a page yet';
    }
    default: return '';
  }
}

function rowThumb(screen, item) {
  if (!['products', 'categories', 'pages'].includes(screen.id)) return null;
  const source = previewUrl(item.picture);
  const thumb = document.createElement('span');
  thumb.className = 'row-thumb';
  if (source) {
    const image = document.createElement('img');
    image.alt = '';
    image.src = source;
    thumb.append(image);
  }
  return thumb;
}

function bulkBar(screen) {
  const bar = document.createElement('div');
  bar.className = 'bulk-bar';
  const count = selected.size;
  const label = document.createElement('span');
  label.className = 'bulk-count';
  label.textContent = count ? `${count} selected` : 'Tick items to change several at once.';

  const actions = document.createElement('div');
  actions.className = 'bulk-actions';
  const categoryPicker = document.createElement('select');
  categoryPicker.disabled = !count;
  categoryPicker.setAttribute('aria-label', 'Move selected items to a category');
  categoryPicker.append(new Option('Move to category...', ''));
  for (const option of optionsFor(states, { optionsFrom: 'categories', labelKey: 'name' }, '')) {
    if (option.value) categoryPicker.append(new Option(option.label, option.value));
  }
  categoryPicker.onchange = () => {
    const value = categoryPicker.value;
    if (!value) return;
    const name = categoryPicker.selectedOptions[0].text;
    bulkApply(screen, `Move to the category "${name}"`, (item) => { item.category = value; });
  };
  actions.append(
    categoryPicker,
    actionButton('Show', 'Show the selected items on the website', !count,
      () => bulkApply(screen, 'Show on the website', (item) => { item.visible = true; })),
    actionButton('Hide', 'Hide the selected items from the website', !count,
      () => bulkApply(screen, 'Hide from the website', (item) => { item.visible = false; })),
    actionButton('Feature', 'Mark the selected items as featured', !count,
      () => bulkApply(screen, 'Mark as featured', (item) => { item.featured = true; })),
    actionButton('Clear', 'Clear the selection', !count, () => {
      selected = new Set();
      renderScreen();
    }),
  );
  bar.append(label, actions);
  return bar;
}

/** Applies one change to every selected item, after confirming what will change. */
function bulkApply(screen, description, change) {
  const chosen = states[screen.id].items.filter((item) => selected.has(String(item.id)));
  if (!chosen.length) return;
  const names = chosen.slice(0, 5).map((item) => item[screen.titleFrom] || 'Untitled');
  const more = chosen.length > 5 ? ` and ${chosen.length - 5} more` : '';
  const plural = chosen.length === 1 ? '' : 's';
  const message = `${description} for ${chosen.length} ${screen.itemLabel}${plural}?\n\n${names.join(', ')}${more}\n\nNothing changes on the website until you publish.`;
  if (!confirm(message)) return;
  chosen.forEach(change);
  selected = new Set();
  markDirty();
  toast(`Changed ${chosen.length} ${screen.itemLabel}${plural}. Save, then publish.`);
  renderScreen();
}

function listPane(screen, items, openItem) {
  const pane = document.createElement('section');
  pane.className = 'list-pane';
  pane.setAttribute('aria-label', `${screen.label} list`);

  if (screen.bulk && selected.size) pane.append(bulkBar(screen));

  const tools = document.createElement('div');
  tools.className = 'list-tools';
  const search = document.createElement('input');
  search.type = 'search';
  search.placeholder = `Search ${screen.label.toLowerCase()}`;
  search.setAttribute('aria-label', `Search ${screen.label.toLowerCase()}`);
  search.value = listQuery;
  const filter = document.createElement('select');
  filter.setAttribute('aria-label', 'Show');
  for (const [value, text] of [['all', 'All'], ['shown', 'Shown on the website'], ['hidden', 'Hidden']]) {
    filter.append(new Option(text, value));
  }
  filter.value = listFilter;
  tools.append(search, filter);
  pane.append(tools);

  const list = document.createElement('ul');
  list.className = 'item-list';
  pane.append(list);

  const draw = () => {
    list.replaceChildren();
    const query = listQuery.trim().toLowerCase();
    const shown = items.filter((item) => {
      const text = `${item[screen.titleFrom] ?? ''} ${rowSummary(screen, item)}`.toLowerCase();
      if (query && !text.includes(query)) return false;
      if (listFilter === 'shown') return item.visible !== false;
      if (listFilter === 'hidden') return item.visible === false;
      return true;
    });
    if (!shown.length) {
      const empty = document.createElement('li');
      empty.className = 'empty-row';
      empty.textContent = items.length ? 'Nothing matches your search.' : `No ${screen.label.toLowerCase()} yet.`;
      list.append(empty);
    }
    for (const item of shown) {
      const row = document.createElement('li');
      row.className = 'item-row';
      const isOpen = openItem && String(openItem.id) === String(item.id);
      row.classList.toggle('open', isOpen);
      if (screen.bulk) {
        const check = document.createElement('input');
        check.type = 'checkbox';
        check.className = 'row-check';
        check.checked = selected.has(String(item.id));
        check.setAttribute('aria-label', `Select ${item[screen.titleFrom] || 'Untitled'}`);
        check.onchange = () => {
          if (check.checked) selected.add(String(item.id));
          else selected.delete(String(item.id));
          renderScreen();
        };
        row.append(check);
      }
      const open = document.createElement('button');
      open.type = 'button';
      open.className = 'row-open';
      if (isOpen) open.setAttribute('aria-current', 'true');
      const thumb = rowThumb(screen, item);
      if (thumb) open.append(thumb);
      const text = document.createElement('span');
      text.className = 'row-text';
      const name = document.createElement('span');
      name.className = 'row-name';
      name.textContent = item[screen.titleFrom] || 'Untitled';
      const meta = document.createElement('span');
      meta.className = 'row-meta';
      meta.textContent = rowSummary(screen, item);
      text.append(name, meta);
      open.append(text);
      if (screen.visibleField !== false && item.visible !== undefined) {
        const pill = document.createElement('span');
        pill.className = `pill ${item.visible ? 'pill-on' : 'pill-off'}`;
        pill.textContent = item.visible ? 'Shown' : 'Hidden';
        open.append(pill);
      }
      open.onclick = () => {
        openIds[screen.id] = String(item.id);
        renderScreen();
      };
      row.append(open);
      list.append(row);
    }
  };

  search.oninput = () => { listQuery = search.value; draw(); };
  filter.onchange = () => { listFilter = filter.value; draw(); };
  draw();

  const add = document.createElement('button');
  add.type = 'button';
  add.className = 'add';
  add.textContent = `Add a ${screen.itemLabel}`;
  add.onclick = () => addItem(screen);
  pane.append(add);
  return pane;
}

function addItem(screen) {
  const state = states[screen.id];
  const item = makeBlankItem(schema, screen.id);
  item.id = nextId(schema, screen.id, state, content);
  for (const field of screen.fields) {
    if (field.optionsFrom && field.emptyLabel === undefined && !item[field.key]) {
      const first = optionsFor(states, field, item.id).find((option) => option.value);
      if (first) item[field.key] = first.value;
    }
  }
  state.items.push(item);
  openIds[screen.id] = String(item.id);
  markDirty();
  renderScreen();
  el('editor').querySelector('input[type="text"]')?.focus();
}

function duplicateItem(screen, item) {
  const state = states[screen.id];
  const copy = { ...item, id: nextId(schema, screen.id, state, content), __row: undefined };
  copy[screen.titleFrom] = `${item[screen.titleFrom] || 'Untitled'} (copy)`;
  copy.slug = '';
  copy.visible = false;
  state.items.push(copy);
  openIds[screen.id] = String(copy.id);
  markDirty();
  toast('Copy created and hidden from the website. Change it, then show it when ready.');
  renderScreen();
}

function moveItem(screen, index, step) {
  const items = states[screen.id].items;
  const target = index + step;
  if (target < 0 || target >= items.length) return;
  [items[index], items[target]] = [items[target], items[index]];
  markDirty();
  renderScreen();
}

function removeItem(screen, item) {
  const problems = blockersFor(states, screen.id, item);
  if (problems.length) {
    toast(problems.join(' '), true);
    return;
  }
  const name = item[screen.titleFrom] || `this ${screen.itemLabel}`;
  if (!confirm(`Delete "${name}"? It leaves the website after you publish.`)) return;
  const items = states[screen.id].items;
  const index = items.indexOf(item);
  items.splice(index, 1);
  openIds[screen.id] = items[0] ? String(items[0].id) : undefined;
  markDirty();
  toast(`Deleted "${name}". Save, then publish to remove it from the website.`);
  renderScreen();
}

function detailPane(screen, items, item) {
  const pane = document.createElement('section');
  pane.className = 'detail-pane';
  if (!item) {
    const empty = document.createElement('div');
    empty.className = 'empty-state';
    empty.append(heading('h2', `No ${screen.label.toLowerCase()} yet`));
    empty.append(hint(`Add a ${screen.itemLabel} to start. Changes appear on the website after you publish.`));
    const add = document.createElement('button');
    add.type = 'button';
    add.className = 'primary';
    add.textContent = `Add a ${screen.itemLabel}`;
    add.onclick = () => addItem(screen);
    empty.append(add);
    pane.append(empty);
    return pane;
  }

  const index = items.indexOf(item);
  const bar = document.createElement('div');
  bar.className = 'detail-bar';
  bar.append(heading('h2', item[screen.titleFrom] || 'Untitled'));
  const actions = document.createElement('div');
  actions.className = 'detail-actions';
  if (screen.sortable) {
    actions.append(
      actionButton('Move up', 'Show this earlier in the list', index === 0, () => moveItem(screen, index, -1)),
      actionButton('Move down', 'Show this later in the list', index === items.length - 1, () => moveItem(screen, index, 1)),
    );
  }
  if (screen.duplicable) {
    actions.append(actionButton('Duplicate', 'Make a hidden copy of this item', false, () => duplicateItem(screen, item)));
  }
  actions.append(actionButton('Delete', `Delete this ${screen.itemLabel}`, false, () => removeItem(screen, item), true));
  bar.append(actions);
  pane.append(bar);

  const body = document.createElement('div');
  body.className = 'detail-body';
  const form = document.createElement('div');
  form.className = 'detail-form';
  const refresh = (key, value) => {
    item[key] = value;
    schedulePreview(screen, item);
    const openName = document.querySelector('.item-row.open .row-name');
    if (openName) openName.textContent = item[screen.titleFrom] || 'Untitled';
  };
  form.append(groupedFields(screen, item, refresh, item.id));
  body.append(form);
  pane.append(body);
  return pane;
}

function renderCollection(editor, screen) {
  const items = states[screen.id].items;
  const openId = openIds[screen.id];
  const item = items.find((entry) => String(entry.id) === String(openId)) ?? items[0];
  if (item) openIds[screen.id] = String(item.id);
  const hasPreview = previewDocument(screen.id, item, states) !== null;
  const workspace = document.createElement('div');
  workspace.className = hasPreview ? 'workspace with-preview' : 'workspace';
  workspace.append(listPane(screen, items, item), detailPane(screen, items, item));
  if (hasPreview) workspace.append(previewPane(screen, item));
  editor.append(workspace);
}

/* ---------- live preview pane ---------- */

let previewFrame = null;
let previewTimer = null;

function previewPane(screen, item) {
  const pane = document.createElement('section');
  pane.className = 'preview-pane';
  const header = document.createElement('div');
  header.className = 'preview-head';
  header.append(heading('p', 'Live preview'), hint('Shows your unsaved changes. Click a block to edit it.'));
  const frame = document.createElement('iframe');
  frame.className = 'preview-frame';
  frame.title = 'Live preview of the website';
  // Scripts run only to report clicks back to the editor. The page itself gets no access to the admin.
  frame.setAttribute('sandbox', 'allow-scripts');
  frame.srcdoc = previewDocument(screen.id, item, states) ?? '';
  previewFrame = frame;
  pane.append(header, frame);
  return pane;
}

/** Re-draws the preview shortly after the editor changes, so typing does not redraw on every key. */
function schedulePreview(screen, item) {
  clearTimeout(previewTimer);
  previewTimer = setTimeout(() => {
    if (!previewFrame?.isConnected) return;
    const doc = previewDocument(screen.id, item, states);
    if (doc !== null) previewFrame.srcdoc = doc;
  }, 150);
}

window.addEventListener('message', (event) => {
  if (!previewFrame || event.source !== previewFrame.contentWindow) return;
  if (event.data?.type !== 'open-item') return;
  const [target, id] = String(event.data.target ?? '').split(':');
  if (!states[target] || !id) return;
  screenId = target;
  openIds[target] = id;
  selected = new Set();
  renderNav();
  renderScreen();
});


/* ---------- settings ---------- */

function renderSettings(editor, screen) {
  const panel = document.createElement('section');
  panel.className = 'settings-form';
  const values = states[screen.id].values;
  panel.append(groupedFields(screen, values, (key, value) => { values[key] = value; }));
  editor.append(panel);
}

/* ---------- media library ---------- */

function usageByKey() {
  const usage = new Map();
  const use = (value, where) => {
    for (const part of String(value ?? '').split('|')) {
      const key = part.trim();
      if (key) usage.set(key, [...(usage.get(key) ?? []), where]);
    }
  };
  for (const item of states.products?.items ?? []) {
    use(item.picture, `Product: ${item.name || 'Untitled'}`);
    use(item.gallery, `Product gallery: ${item.name || 'Untitled'}`);
  }
  for (const item of states.pages?.items ?? []) use(item.picture, `Page: ${item.title || 'Untitled'}`);
  for (const item of states.categories?.items ?? []) use(item.picture, `Category: ${item.name || 'Untitled'}`);
  for (const key of ['site_logo', 'favicon']) use(states.settings?.values?.[key], `Settings: ${key}`);
  return usage;
}

async function renderMedia(editor) {
  const page = document.createElement('section');
  page.className = 'media-page';
  const bar = document.createElement('div');
  bar.className = 'toolbar';
  const picker = document.createElement('input');
  picker.type = 'file';
  picker.multiple = true;
  picker.accept = IMAGE_TYPES;
  picker.hidden = true;
  picker.onchange = async () => {
    for (const file of [...(picker.files ?? [])]) {
      const form = new FormData();
      form.append('file', file);
      try {
        await api('/api/image', { method: 'POST', body: form });
      } catch (error) {
        toast(error.message, true);
        break;
      }
    }
    toast('Pictures added to the library.');
    renderScreen();
  };
  const upload = document.createElement('label');
  upload.className = 'file-button primary-look';
  upload.textContent = 'Upload pictures';
  upload.append(picker);
  const missingFilter = document.createElement('label');
  missingFilter.className = 'switch';
  const missingBox = document.createElement('input');
  missingBox.type = 'checkbox';
  missingFilter.append(missingBox, document.createTextNode('Show only pictures missing alt text'));
  bar.append(upload, missingFilter);
  page.append(bar, hint('Alt text describes a picture for people who cannot see it. Add it to every picture you upload.'));

  const grid = document.createElement('div');
  grid.className = 'media-grid';
  page.append(grid);
  editor.append(page);

  let library;
  try {
    library = (await api('/api/media')).items;
  } catch (error) {
    grid.textContent = error.message;
    return;
  }

  const usage = usageByKey();
  const inLibrary = new Set(library.map((item) => item.key));
  const older = [...usage.keys()].filter((key) => !inLibrary.has(key))
    .map((key) => ({ key, name: key.split('/').pop(), alt: '', older: true }));
  const entries = [...library, ...older];

  const draw = () => {
    grid.replaceChildren();
    const shown = entries.filter((entry) => !missingBox.checked || !String(entry.alt ?? '').trim());
    if (!shown.length) {
      grid.append(hint(missingBox.checked ? 'Every picture has alt text.' : 'No pictures yet. Upload one to start the library.'));
      return;
    }
    for (const entry of shown) {
      const card = document.createElement('figure');
      card.className = 'media-card';
      const image = document.createElement('img');
      image.alt = '';
      image.src = previewUrl(entry.key) ?? '';
      const name = document.createElement('p');
      name.className = 'media-name';
      name.textContent = entry.name || entry.key;
      const where = document.createElement('p');
      where.className = 'hint';
      const uses = usage.get(entry.key) ?? [];
      where.textContent = uses.length ? `Used in ${uses.join('; ')}` : 'Not used anywhere yet.';
      const altId = nextFieldId();
      const altLabel = document.createElement('label');
      altLabel.textContent = 'Alt text';
      altLabel.htmlFor = altId;
      const alt = document.createElement('input');
      alt.type = 'text';
      alt.id = altId;
      alt.maxLength = 300;
      alt.value = entry.alt ?? '';
      alt.placeholder = 'Describe the picture';
      alt.disabled = Boolean(entry.older);
      const save = actionButton('Save alt text', 'Save the alt text for this picture', Boolean(entry.older), async () => {
        try {
          await api('/api/media/alt', {
            method: 'POST',
            headers: { 'content-type': 'application/json' },
            body: JSON.stringify({ key: entry.key, alt: alt.value }),
          });
          entry.alt = alt.value.trim();
          toast('Alt text saved.');
        } catch (error) {
          toast(error.message, true);
        }
      });
      card.append(image, name, where, altLabel, alt, save);
      if (entry.older) card.append(hint('Uploaded before the library existed. Upload it again to add alt text.'));
      grid.append(card);
    }
  };
  missingBox.onchange = draw;
  draw();
}

/* ---------- history and schedule ---------- */

function formatTime(iso) {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

async function schedulePanel() {
  const box = document.createElement('section');
  box.className = 'card-panel';
  box.append(heading('h2', 'Scheduled publish'));
  box.append(hint('The website is published at the time you choose, using the last saved version. Changes you have not saved are not included.'));

  let scheduled = null;
  try {
    scheduled = (await api('/api/schedule')).publish_at;
  } catch {
    // Without the schedule, show the form. Saving reports any problem.
  }

  const row = document.createElement('div');
  row.className = 'schedule-row';
  if (scheduled) {
    const text = document.createElement('span');
    text.textContent = `Scheduled for ${formatTime(scheduled)}.`;
    row.append(text, actionButton('Cancel schedule', 'Cancel the scheduled publish', false, async () => {
      try {
        await api('/api/schedule', {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ publish_at: null }),
        });
        toast('Scheduled publish cancelled.');
        renderScreen();
      } catch (error) {
        toast(error.message, true);
      }
    }, true));
  } else {
    const input = document.createElement('input');
    input.type = 'datetime-local';
    input.setAttribute('aria-label', 'Publish date and time');
    const local = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    input.min = local.toISOString().slice(0, 16);
    row.append(input, actionButton('Schedule publish', 'Publish at this time', false, async () => {
      if (!input.value) {
        toast('Choose a date and time first.', true);
        return;
      }
      try {
        const publishAt = new Date(input.value).toISOString();
        await api('/api/schedule', {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ publish_at: publishAt }),
        });
        toast(`Publish scheduled for ${formatTime(publishAt)}.`);
        renderScreen();
      } catch (error) {
        toast(error.message, true);
      }
    }));
  }
  box.append(row);
  return box;
}

async function renderHistory(editor) {
  const versions = document.createElement('section');
  versions.className = 'card-panel';
  versions.append(heading('h2', 'Saved versions'));
  versions.append(hint('Each save keeps the version it replaces. Restoring puts that version back, and the version you restore over is kept too.'));
  const versionList = document.createElement('div');
  versionList.className = 'history-list';
  versionList.textContent = 'Loading…';
  versions.append(versionList);

  const activity = document.createElement('section');
  activity.className = 'card-panel';
  activity.append(heading('h2', 'Recent activity'));
  const activityList = document.createElement('div');
  activityList.className = 'history-list';
  activityList.textContent = 'Loading…';
  activity.append(activityList);

  editor.append(await schedulePanel(), versions, activity);

  let history;
  try {
    history = await api('/api/history');
  } catch (error) {
    versionList.textContent = error.message;
    activityList.textContent = '';
    return;
  }

  versionList.replaceChildren();
  if (!history.versions.length) versionList.append(hint('No earlier versions yet. The next save creates the first one.'));
  for (const version of history.versions) {
    const row = document.createElement('div');
    row.className = 'history-row';
    const when = document.createElement('span');
    when.textContent = `Saved ${formatTime(version.saved_at)}`;
    row.append(when, actionButton('Restore', 'Put this version back', false, () => restoreVersion(version)));
    versionList.append(row);
  }

  activityList.replaceChildren();
  if (!history.audit.length) activityList.append(hint('Nothing yet.'));
  for (const entry of history.audit.slice(0, 50)) {
    const row = document.createElement('div');
    row.className = 'history-row';
    const tabs = entry.tabs?.length ? ` (${entry.tabs.join(', ')})` : '';
    const who = ROLE_NAMES[entry.by] ?? entry.by;
    row.textContent = `${formatTime(entry.at)}: ${entry.action}${tabs}, by ${who}`;
    activityList.append(row);
  }
}

async function restoreVersion(version) {
  const warning = dirty ? '\n\nYour unsaved changes will be lost.' : '';
  if (!confirm(`Put the website back to the version saved ${formatTime(version.saved_at)}?${warning}`)) return;
  try {
    await api('/api/restore', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ id: version.id }),
    });
    toast('Version restored. Publish to put it on the website.');
    await start();
  } catch (error) {
    toast(error.message, true);
  }
}

/* ---------- screen router ---------- */

function renderScreen() {
  const editor = el('editor');
  editor.replaceChildren();
  editor.inert = role !== 'admin';
  el('screen-title').textContent = screenName(screenId);
  updateButtons();

  if (screenId === HISTORY) {
    renderHistory(editor);
    return;
  }
  if (screenId === MEDIA) {
    renderMedia(editor);
    return;
  }

  const screen = screens(schema).find((item) => item.id === screenId);
  if (selectedFor !== screen.id) {
    selected = new Set();
    selectedFor = screen.id;
    listQuery = '';
    listFilter = 'all';
  }
  if (screen.layout === 'key-value') {
    renderSettings(editor, screen);
  } else {
    renderCollection(editor, screen);
  }
}

/* ---------- saving and publishing ---------- */

async function refreshStatus() {
  try {
    const status = await api('/api/status');
    setStatus(status.status === 'never' ? 'Nothing published yet.' : status.message);
  } catch (error) {
    setStatus(error.message, true);
  }
}

function watchPublish() {
  const timer = setInterval(async () => {
    try {
      const status = await api('/api/status');
      if (!BUSY.includes(status.status)) {
        clearInterval(timer);
        const link = status.status === 'preview-ready' ? ` Open ${PREVIEW_URL}` : '';
        setStatus(`${status.message}${link}`);
        toast(status.message);
      }
    } catch {
      clearInterval(timer);
    }
  }, 3000);
}

/** Checks the form, then saves every screen to the store. Publishing and previews build from this copy. */
async function saveDraft() {
  normalizeSlugs(schema, states);
  const problems = slugProblems(schema, states);
  if (problems.length) {
    throw new Error(problems[0] + (problems.length > 1 ? ` (${problems.length - 1} more to fix)` : ''));
  }

  // Changed web addresses need redirects. The Redirects screen is merged first so its unsaved rows are kept.
  const changes = redirectsForSlugChanges(schema, states, content);
  if (changes.length) {
    const withEdits = toContentJson(schema, 'redirects', states.redirects, content);
    states.redirects = fromContentJson(schema, 'redirects', applyRedirects(withEdits, changes));
  }

  for (const screen of screens(schema)) {
    content = toContentJson(schema, screen.id, states[screen.id], content);
  }
  setStatus('Saving…');
  const saved = await request('/api/content', {
    method: 'PUT',
    headers: { 'content-type': 'application/json', 'x-expected-version': contentVersion },
    body: JSON.stringify(content),
  });
  contentVersion = saved.headers.get('x-content-version') ?? contentVersion;
  dirty = false;
  setStatus('All changes saved.');
  updateButtons();
}

async function saveChanges() {
  try {
    await saveDraft();
    toast('Changes saved. Publish when you are ready to put them on the website.');
    renderScreen();
  } catch (error) {
    toast(error.message, true);
  }
}

async function publish() {
  try {
    await saveDraft();
    await api('/api/publish', { method: 'POST' });
    toast('Publishing started. This takes a few minutes.');
    setStatus('Publishing…');
    watchPublish();
  } catch (error) {
    toast(error.message, true);
  }
}

async function preview() {
  try {
    await saveDraft();
    await api('/api/preview', { method: 'POST' });
    toast('Building a preview. It will open at the preview address when ready.');
    setStatus(`Building a preview at ${PREVIEW_URL}`);
    watchPublish();
  } catch (error) {
    toast(error.message, true);
  }
}

function downloadBackup(event) {
  event.preventDefault();
  const blob = new Blob([JSON.stringify(content, null, 2)], { type: 'application/json' });
  const link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = 'website-backup.json';
  link.click();
  URL.revokeObjectURL(link.href);
}

el('sign-in').onclick = signIn;
el('password').onkeydown = (event) => { if (event.key === 'Enter') signIn(); };
el('save').onclick = saveChanges;
el('publish').onclick = publish;
el('preview').onclick = preview;
el('logout').onclick = signOut;
el('backup').onclick = downloadBackup;
document.addEventListener('keydown', (event) => {
  if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's' && role === 'admin' && !el('app').hidden) {
    event.preventDefault();
    if (dirty) saveChanges();
  }
});
window.addEventListener('beforeunload', (event) => {
  if (dirty) event.preventDefault();
});
start().catch((error) => {
  console.error(error);
  setStatus(`Something went wrong: ${error.message}`, true);
});
