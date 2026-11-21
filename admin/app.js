import { IMAGE_BASE_URL, PREVIEW_URL, WORKER_URL } from './config.js';
import {
  applyRedirects, blockersFor, fromContentJson, linkOptions, makeBlankItem, nextId, normalizeSlugs, optionsFor,
  redirectsForSlugChanges, screens, slugProblems, toContentJson,
} from './fields.js';

const el = (id) => document.getElementById(id);
const IMAGE_TYPES = 'image/jpeg,image/png,image/webp,image/gif';
const HISTORY = '__history';
const MEDIA = '__media';
const ROLE_NAMES = { admin: 'administrator', viewer: 'view-only account' };
let schema;
let content;
let contentVersion = '';
let role = 'viewer';
let screenId;
let dirty = false;
let selected = new Set();
let selectedFor = null;
const states = {};

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

function setStatus(message, isError = false) {
  const status = el('status');
  status.textContent = message;
  status.classList.toggle('error', isError);
}

function markDirty() {
  dirty = true;
  setStatus('Unsaved changes. Press Publish to put them on the website.');
}

function showSignIn(message = '') {
  el('login').hidden = false;
  el('app').hidden = true;
  el('login-error').textContent = message;
}

function applyRole() {
  const admin = role === 'admin';
  el('publish').hidden = !admin;
  el('preview').hidden = !admin;
  el('role').hidden = admin;
}

async function signIn() {
  try {
    await api('/api/login', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ password: el('password').value }),
    });
    el('password').value = '';
    await start();
  } catch (error) {
    el('login-error').textContent = error.message;
  }
}

async function signOut() {
  const warning = dirty ? '\n\nUnpublished changes will be lost.' : '';
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
  applyRole();
  for (const screen of screens(schema)) states[screen.id] = fromContentJson(schema, screen.id, content);
  el('login').hidden = true;
  el('app').hidden = false;
  screenId = screenId ?? screens(schema)[0].id;
  renderNav();
  renderScreen();
  refreshStatus();
}

function renderNav() {
  const nav = el('screens');
  nav.replaceChildren();
  const entries = [...screens(schema).map((screen) => ({ id: screen.id, label: screen.label })),
    { id: MEDIA, label: 'Media' }, { id: HISTORY, label: 'History' }];
  for (const entry of entries) {
    const button = document.createElement('button');
    button.textContent = entry.label;
    button.classList.toggle('active', entry.id === screenId);
    button.onclick = () => { screenId = entry.id; renderNav(); renderScreen(); };
    nav.append(button);
  }
}

function previewUrl(value) {
  if (!value) return null;
  if (/^https?:\/\//i.test(value)) return value;
  return `${IMAGE_BASE_URL.replace(/\/$/, '')}/${String(value).replace(/^\//, '')}`;
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

function galleryControl(value, change) {
  const keys = String(value ?? '').split('|').map((key) => key.trim()).filter(Boolean);
  const box = document.createElement('div');
  box.className = 'gallery';

  const save = () => { change(keys.join('|')); draw(); };

  function draw() {
    box.replaceChildren();
    const list = document.createElement('div');
    list.className = 'thumbs';
    keys.forEach((key, index) => {
      const card = document.createElement('figure');
      card.className = 'thumb';
      const image = document.createElement('img');
      image.alt = '';
      image.src = previewUrl(key) ?? '';
      const actions = document.createElement('div');
      actions.className = 'thumb-actions';
      actions.append(
        actionButton('â—€', 'Move picture left', index === 0, () => {
          [keys[index - 1], keys[index]] = [keys[index], keys[index - 1]];
          save();
        }),
        actionButton('â–¶', 'Move picture right', index === keys.length - 1, () => {
          [keys[index], keys[index + 1]] = [keys[index + 1], keys[index]];
          save();
        }),
        actionButton('Remove', 'Remove this picture', false, () => {
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
          setStatus(error.message, true);
          break;
        }
      }
      if (added) {
        save();
        setStatus(`${added} picture${added === 1 ? '' : 's'} added. Press Publish to put them on the website.`);
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
  const change = (next) => { onChange(next); markDirty(); };
  const wrapper = document.createElement('div');
  wrapper.className = 'field';

  if (field.type === 'checkbox') {
    const label = document.createElement('label');
    label.className = 'check';
    const input = document.createElement('input');
    input.type = 'checkbox';
    input.checked = Boolean(value);
    input.onchange = () => change(input.checked);
    label.append(input, document.createTextNode(` ${field.label}`));
    wrapper.append(label);
    return wrapper;
  }

  const label = document.createElement('label');
  label.textContent = field.label;
  wrapper.append(label);

  if (field.type === 'image') {
    const node = el('image-field').content.cloneNode(true);
    const image = node.querySelector('img');
    const source = previewUrl(value);
    if (source) { image.src = source; image.hidden = false; }
    const picker = node.querySelector('input');
    picker.onchange = async () => {
      if (!picker.files?.[0]) return;
      const form = new FormData();
      form.append('file', picker.files[0]);
      try {
        const result = await api('/api/image', { method: 'POST', body: form });
        change(result.key);
        if (result.url) { image.src = result.url; image.hidden = false; }
        setStatus('Picture ready. Click Publish to make it live.');
      } catch (error) {
        setStatus(error.message, true);
      }
    };
    wrapper.append(node);
    return wrapper;
  }

  if (field.type === 'gallery') {
    wrapper.append(galleryControl(value, change));
    return wrapper;
  }

  if (field.type === 'select') {
    const select = document.createElement('select');
    const options = optionsFor(states, field, itemId);
    for (const option of options) select.append(new Option(option.label, option.value));
    if (value && !options.some((option) => option.value === String(value))) {
      select.append(new Option(`${value} (missing)`, value));
    }
    select.value = String(value ?? '');
    select.onchange = () => change(select.value);
    wrapper.append(select);
    return wrapper;
  }

  if (field.type === 'link') {
    const input = document.createElement('input');
    input.type = 'text';
    input.value = value ?? '';
    input.placeholder = 'Type an address, or pick below';
    input.oninput = () => change(input.value);

    const picker = document.createElement('select');
    picker.append(new Option('Choose a page, category or product...', ''));
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
    return wrapper;
  }

  if (field.help) wrapper.append(hint(field.help));
  const input = document.createElement(field.type === 'textarea' ? 'textarea' : 'input');
  if (input.tagName === 'INPUT') input.type = ['color', 'email', 'tel'].includes(field.type) ? field.type : 'text';
  input.value = value ?? '';
  input.oninput = () => change(input.value);
  wrapper.append(input);
  return wrapper;
}

function helpPanel(screen) {
  const box = document.createElement('section');
  box.className = 'help';
  const title = document.createElement('h2');
  title.textContent = 'How this works';
  const explanation = document.createElement('p');
  explanation.textContent = screen.help ?? '';
  const impact = document.createElement('p');
  const strong = document.createElement('strong');
  strong.textContent = 'What it changes: ';
  impact.append(strong, document.createTextNode(screen.impact ?? ''));
  box.append(title, explanation, impact);
  return box;
}

function moveItem(id, index, step) {
  const items = states[id].items;
  const target = index + step;
  if (target < 0 || target >= items.length) return;
  [items[index], items[target]] = [items[target], items[index]];
  markDirty();
  renderScreen();
}

function removeItem(screen, index) {
  const item = states[screen.id].items[index];
  const problems = blockersFor(states, screen.id, item);
  if (problems.length) {
    setStatus(problems.join(' '), true);
    return;
  }
  const name = item[screen.titleFrom] || `this ${screen.itemLabel}`;
  if (!confirm(`Delete "${name}"? It leaves the website after you press Publish.`)) return;
  states[screen.id].items.splice(index, 1);
  markDirty();
  renderScreen();
}

function itemActions(screen, index) {
  const bar = document.createElement('div');
  bar.className = 'item-actions';
  const count = states[screen.id].items.length;
  if (screen.sortable) {
    bar.append(
      actionButton('Move up', 'Show this earlier', index === 0, () => moveItem(screen.id, index, -1)),
      actionButton('Move down', 'Show this later', index === count - 1, () => moveItem(screen.id, index, 1)),
    );
  }
  bar.append(actionButton('Delete', `Delete this ${screen.itemLabel}`, false, () => removeItem(screen, index), true));
  return bar;
}

/** Applies one change to every selected item, after the editor shows what will change. */
function bulkApply(screen, description, change) {
  const chosen = states[screen.id].items.filter((item) => selected.has(String(item.id)));
  if (!chosen.length) return;
  const names = chosen.slice(0, 5).map((item) => item[screen.titleFrom] || 'Untitled');
  const more = chosen.length > 5 ? ` and ${chosen.length - 5} more` : '';
  const plural = chosen.length === 1 ? '' : 's';
  const message = `${description} for ${chosen.length} ${screen.itemLabel}${plural}?\n\n${names.join(', ')}${more}\n\nNothing changes on the website until you press Publish.`;
  if (!confirm(message)) return;
  chosen.forEach(change);
  selected = new Set();
  markDirty();
  renderScreen();
}

function bulkBar(screen) {
  const bar = document.createElement('div');
  bar.className = 'bulk-bar';
  const count = selected.size;

  const label = document.createElement('span');
  label.className = 'bulk-count';
  label.textContent = count ? `${count} selected` : 'Tick items below to change several at once.';

  const actions = document.createElement('div');
  actions.className = 'bulk-actions';
  const categoryPicker = document.createElement('select');
  categoryPicker.disabled = !count;
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
    actionButton('Unfeature', 'Remove the featured mark', !count,
      () => bulkApply(screen, 'Remove the featured mark', (item) => { item.featured = false; })),
    actionButton('Select all', 'Select every item on this screen', false, () => {
      selected = new Set(states[screen.id].items.map((item) => String(item.id)));
      renderScreen();
    }),
    actionButton('Clear', 'Clear the selection', !count, () => {
      selected = new Set();
      renderScreen();
    }),
  );
  bar.append(label, actions);
  return bar;
}

function selectRow(item) {
  const label = document.createElement('label');
  label.className = 'check select-row';
  const input = document.createElement('input');
  input.type = 'checkbox';
  input.checked = selected.has(String(item.id));
  input.onchange = () => {
    if (input.checked) selected.add(String(item.id));
    else selected.delete(String(item.id));
    renderScreen();
  };
  label.append(input, document.createTextNode(' Select for bulk change'));
  return label;
}

function renderScreen() {
  const editor = el('editor');
  editor.replaceChildren();
  editor.inert = role !== 'admin';

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
  }
  editor.append(helpPanel(screen));

  if (screen.layout === 'key-value') {
    const panel = document.createElement('section');
    panel.className = 'panel';
    panel.append(Object.assign(document.createElement('h2'), { textContent: screen.label }));
    for (const field of screen.fields) {
      panel.append(makeField(field, states[screen.id].values[field.key], (value) => {
        states[screen.id].values[field.key] = value;
      }));
    }
    editor.append(panel);
    return;
  }

  if (screen.bulk) editor.append(bulkBar(screen));

  states[screen.id].items.forEach((item, index) => {
    const panel = document.createElement('section');
    panel.className = 'panel';
    const name = item[screen.titleFrom] || 'Untitled';
    panel.append(Object.assign(document.createElement('h2'), {
      textContent: `${index + 1}. ${name}`,
    }));
    if (screen.bulk) panel.append(selectRow(item));
    for (const field of screen.fields) {
      panel.append(makeField(field, item[field.key], (value) => { item[field.key] = value; }, item.id));
    }
    panel.append(itemActions(screen, index));
    editor.append(panel);
  });

  const add = document.createElement('button');
  add.className = 'add';
  add.textContent = `Add a ${screen.itemLabel}`;
  add.onclick = () => {
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
    markDirty();
    renderScreen();
  };
  editor.append(add);
}

function formatTime(iso) {
  return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

async function renderHistory(editor) {
  const versions = document.createElement('section');
  versions.className = 'panel';
  versions.append(Object.assign(document.createElement('h2'), { textContent: 'Saved versions' }));
  versions.append(hint('Each save keeps the version it replaces. Restoring puts that version back, and the version you restore over is kept too.'));
  const versionList = document.createElement('div');
  versionList.className = 'history-list';
  versionList.textContent = 'Loadingâ€¦';
  versions.append(versionList);

  const activity = document.createElement('section');
  activity.className = 'panel';
  activity.append(Object.assign(document.createElement('h2'), { textContent: 'Recent activity' }));
  const activityList = document.createElement('div');
  activityList.className = 'history-list';
  activityList.textContent = 'Loadingâ€¦';
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
  if (!history.versions.length) versionList.textContent = 'No earlier versions yet. The next save creates the first one.';
  for (const version of history.versions) {
    const row = document.createElement('div');
    row.className = 'history-row';
    const when = document.createElement('span');
    when.textContent = `Saved ${formatTime(version.saved_at)}`;
    row.append(when, actionButton('Restore', 'Put this version back', false, () => restoreVersion(version)));
    versionList.append(row);
  }

  activityList.replaceChildren();
  if (!history.audit.length) activityList.textContent = 'Nothing yet.';
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
  const warning = dirty ? '\n\nYour unpublished changes will be lost.' : '';
  if (!confirm(`Put the website back to the version saved ${formatTime(version.saved_at)}?${warning}`)) return;
  try {
    await api('/api/restore', {
      method: 'POST',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify({ id: version.id }),
    });
    setStatus('Version restored. Press Publish to put it on the website.');
    await start();
  } catch (error) {
    setStatus(error.message, true);
  }
}

/** Every place a picture is used, keyed by the picture's stored key. */
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
  const panel = document.createElement('section');
  panel.className = 'panel';
  panel.append(Object.assign(document.createElement('h2'), { textContent: 'Media library' }));
  panel.append(hint('Every picture uploaded here is kept, with its alt text. Alt text describes a picture for people who cannot see it. The list also shows where each picture is used.'));

  const toolbar = document.createElement('div');
  toolbar.className = 'media-toolbar';
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
        setStatus(error.message, true);
        break;
      }
    }
    setStatus('Pictures uploaded to the library.');
    renderScreen();
  };
  const upload = document.createElement('label');
  upload.className = 'file-button';
  upload.textContent = 'Upload pictures';
  upload.append(picker);

  const missingFilter = document.createElement('label');
  missingFilter.className = 'check';
  const missingBox = document.createElement('input');
  missingBox.type = 'checkbox';
  missingFilter.append(missingBox, document.createTextNode(' Show only pictures missing alt text'));
  toolbar.append(upload, missingFilter);
  panel.append(toolbar);

  const grid = document.createElement('div');
  grid.className = 'media-grid';
  panel.append(grid);
  editor.append(panel);

  let library;
  try {
    library = (await api('/api/media')).items;
  } catch (error) {
    grid.textContent = error.message;
    return;
  }

  const usage = usageByKey();
  const inLibrary = new Set(library.map((item) => item.key));
  // Pictures used before the library existed are listed too, but their alt text cannot be saved yet.
  const older = [...usage.keys()].filter((key) => !inLibrary.has(key))
    .map((key) => ({ key, name: key.split('/').pop(), alt: '', older: true }));
  const entries = [...library, ...older];

  const draw = () => {
    grid.replaceChildren();
    const shown = entries.filter((entry) => !missingBox.checked || !String(entry.alt ?? '').trim());
    if (!shown.length) {
      grid.textContent = missingBox.checked ? 'Every picture has alt text.' : 'No pictures yet.';
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
      where.textContent = uses.length ? `Used in: ${uses.join('; ')}` : 'Not used on any page yet.';

      const altLabel = document.createElement('label');
      altLabel.textContent = 'Alt text';
      const alt = document.createElement('input');
      alt.type = 'text';
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
          setStatus('Alt text saved.');
        } catch (error) {
          setStatus(error.message, true);
        }
      });
      const olderNote = entry.older ? hint('Uploaded before the library existed. Upload it again to add alt text.') : null;
      card.append(image, name, where, altLabel, alt, save);
      if (olderNote) card.append(olderNote);
      grid.append(card);
    }
  };
  missingBox.onchange = draw;
  draw();
}

async function schedulePanel() {
  const box = document.createElement('section');
  box.className = 'panel';
  box.append(Object.assign(document.createElement('h2'), { textContent: 'Scheduled publish' }));
  box.append(hint('The website is published at the time you choose, using the last saved version. Changes you have not saved are not included.'));

  let scheduled = null;
  try {
    scheduled = (await api('/api/schedule')).publish_at;
  } catch {
    // Without the schedule, show the form. Saving will report any problem.
  }

  const row = document.createElement('div');
  row.className = 'schedule-row';
  if (scheduled) {
    const text = document.createElement('span');
    text.textContent = `Scheduled for ${formatTime(scheduled)}.`;
    row.append(text, actionButton('Cancel', 'Cancel the scheduled publish', false, async () => {
      try {
        await api('/api/schedule', {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ publish_at: null }),
        });
        setStatus('Scheduled publish cancelled.');
        renderScreen();
      } catch (error) {
        setStatus(error.message, true);
      }
    }, true));
  } else {
    const input = document.createElement('input');
    input.type = 'datetime-local';
    const local = new Date(Date.now() - new Date().getTimezoneOffset() * 60000);
    input.min = local.toISOString().slice(0, 16);
    row.append(input, actionButton('Schedule publish', 'Publish at this time', false, async () => {
      if (!input.value) {
        setStatus('Choose a date and time first.', true);
        return;
      }
      try {
        const publishAt = new Date(input.value).toISOString();
        await api('/api/schedule', {
          method: 'POST',
          headers: { 'content-type': 'application/json' },
          body: JSON.stringify({ publish_at: publishAt }),
        });
        setStatus(`Publish scheduled for ${formatTime(publishAt)}.`);
        renderScreen();
      } catch (error) {
        setStatus(error.message, true);
      }
    }));
  }
  box.append(row);
  return box;
}

async function refreshStatus() {
  try {
    const status = await api('/api/status');
    setStatus(status.status === 'never' ? 'Nothing published yet.' : status.message);
  } catch (error) {
    setStatus(error.message, true);
  }
}

const BUSY = ['publishing', 'previewing'];

function watchPublish() {
  const timer = setInterval(async () => {
    try {
      const status = await api('/api/status');
      if (!BUSY.includes(status.status)) {
        clearInterval(timer);
        const link = status.status === 'preview-ready' ? ` Open ${PREVIEW_URL}` : '';
        setStatus(`${status.message}${link}`);
      }
    } catch {
      clearInterval(timer);
    }
  }, 3000);
}

/** Saves every screen to the store. Publishing and previews both build from this saved copy. */
async function saveDraft() {
  normalizeSlugs(schema, states);
  const problems = slugProblems(schema, states);
  if (problems.length) throw new Error(problems[0] + (problems.length > 1 ? ` (${problems.length - 1} more to fix)` : ''));

  // Changed web addresses need redirects. The Redirects screen is merged first so its unsaved rows are kept.
  const changes = redirectsForSlugChanges(schema, states, content);
  if (changes.length) {
    const withEdits = toContentJson(schema, 'redirects', states.redirects, content);
    states.redirects = fromContentJson(schema, 'redirects', applyRedirects(withEdits, changes));
  }

  for (const screen of screens(schema)) {
    content = toContentJson(schema, screen.id, states[screen.id], content);
  }
  setStatus('Savingâ€¦');
  const saved = await request('/api/content', {
    method: 'PUT',
    headers: { 'content-type': 'application/json', 'x-expected-version': contentVersion },
    body: JSON.stringify(content),
  });
  contentVersion = saved.headers.get('x-content-version') ?? contentVersion;
  dirty = false;
}

async function publish() {
  try {
    await saveDraft();
    setStatus('Sending to the websiteâ€¦');
    await api('/api/publish', { method: 'POST' });
    setStatus('Publishingâ€¦');
    watchPublish();
  } catch (error) {
    setStatus(error.message, true);
  }
}

async function preview() {
  try {
    await saveDraft();
    setStatus('Building a previewâ€¦');
    await api('/api/preview', { method: 'POST' });
    setStatus(`Building a preview. It will open at ${PREVIEW_URL} when ready.`);
    watchPublish();
  } catch (error) {
    setStatus(error.message, true);
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
el('publish').onclick = publish;
el('preview').onclick = preview;
el('logout').onclick = signOut;
el('backup').onclick = downloadBackup;
window.addEventListener('beforeunload', (event) => {
  if (dirty) event.preventDefault();
});
start();
