import { IMAGE_BASE_URL, WORKER_URL } from './config.js';
import { fromContentJson, makeBlankItem, screens, toContentJson } from './fields.js';

const el = (id) => document.getElementById(id);
let schema;
let content;
let screenId;
const states = {};

async function api(path, options = {}) {
  const response = await fetch(WORKER_URL + path, { credentials: 'include', ...options });
  const body = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(body.error || 'Something went wrong.');
    error.status = response.status;
    throw error;
  }
  return body;
}

function setStatus(message, isError = false) {
  const status = el('status');
  status.textContent = message;
  status.classList.toggle('error', isError);
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

async function start() {
  if (!schema) schema = await (await fetch('./schema.json')).json();
  try {
    content = await api('/api/content');
  } catch (error) {
    el('login').hidden = false;
    el('app').hidden = true;
    el('login-error').textContent = error.status === 401 ? '' : error.message;
    return;
  }
  el('login').hidden = true;
  el('app').hidden = false;
  for (const screen of screens(schema)) states[screen.id] = fromContentJson(schema, screen.id, content);
  screenId = screenId ?? screens(schema)[0].id;
  renderNav();
  renderScreen();
  refreshStatus();
}

function renderNav() {
  const nav = el('screens');
  nav.replaceChildren();
  for (const screen of screens(schema)) {
    const button = document.createElement('button');
    button.textContent = screen.label;
    button.classList.toggle('active', screen.id === screenId);
    button.onclick = () => { screenId = screen.id; renderNav(); renderScreen(); };
    nav.append(button);
  }
}

function previewUrl(value) {
  if (!value) return null;
  if (/^https?:\/\//i.test(value)) return value;
  return `${IMAGE_BASE_URL.replace(/\/$/, '')}/${String(value).replace(/^\//, '')}`;
}

function makeField(field, value, onChange) {
  const wrapper = document.createElement('div');
  wrapper.className = 'field';
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
        onChange(result.key);
        if (result.url) { image.src = result.url; image.hidden = false; }
        setStatus('Picture ready. Click Publish to make it live.');
      } catch (error) {
        setStatus(error.message, true);
      }
    };
    wrapper.append(node);
    return wrapper;
  }

  const input = document.createElement(field.type === 'textarea' ? 'textarea' : 'input');
  if (input.tagName === 'INPUT') input.type = ['color', 'email', 'tel'].includes(field.type) ? field.type : 'text';
  input.value = value ?? '';
  input.oninput = () => onChange(input.value);
  wrapper.append(input);
  return wrapper;
}

function renderScreen() {
  const screen = screens(schema).find((item) => item.id === screenId);
  const editor = el('editor');
  editor.replaceChildren();

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

  states[screen.id].items.forEach((item, index) => {
    const panel = document.createElement('section');
    panel.className = 'panel';
    panel.append(Object.assign(document.createElement('h2'), {
      textContent: `${screen.itemLabel} ${index + 1}: ${item[screen.slugFrom] || 'Untitled'}`,
    }));
    for (const field of screen.fields) {
      panel.append(makeField(field, item[field.key], (value) => { item[field.key] = value; }));
    }
    editor.append(panel);
  });

  const add = document.createElement('button');
  add.textContent = `Add a ${screen.itemLabel}`;
  add.onclick = () => {
    states[screen.id].items.push(makeBlankItem(schema, screen.id));
    renderScreen();
  };
  editor.append(add);
}

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
      if (status.status !== 'publishing') {
        clearInterval(timer);
        setStatus(status.message);
      }
    } catch {
      clearInterval(timer);
    }
  }, 3000);
}

async function publish() {
  try {
    for (const screen of screens(schema)) {
      content = toContentJson(schema, screen.id, states[screen.id], content);
    }
    setStatus('Saving…');
    await api('/api/content', {
      method: 'PUT',
      headers: { 'content-type': 'application/json' },
      body: JSON.stringify(content),
    });
    setStatus('Sending to the website…');
    await api('/api/publish', { method: 'POST' });
    setStatus('Publishing…');
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
el('backup').onclick = downloadBackup;
start();
