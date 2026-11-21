import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import {
  fromContentJson, labelFor, makeBlankItem, screens, toContentJson,
} from '../fields.js';

const schema = JSON.parse(readFileSync(new URL('../schema.json', import.meta.url), 'utf8'));
const fixture = JSON.parse(readFileSync(new URL('../../tests/Fixtures/sheet-data.json', import.meta.url), 'utf8'));

test('schema exposes Pages, Sections, Products, Categories, Menu, Redirects and Settings', () => {
  assert.deepEqual(screens(schema).map((screen) => screen.label), ['Pages', 'Sections', 'Products', 'Categories', 'Menu', 'Redirects', 'Settings']);
});

test('no technical word appears in any screen or field label', () => {
  const labels = screens(schema).flatMap((screen) => [screen.label, ...screen.fields.map((field) => field.label)]);
  for (const label of labels) {
    assert.doesNotMatch(label.toLowerCase(), /slug|json|\br2\b|\btab\b|token|api/i, label);
  }
});

test('renaming a label in the schema changes labelFor without code changes', () => {
  const changed = structuredClone(schema);
  changed.screens[0].fields[0].label = 'Heading';
  assert.equal(labelFor(changed, 'pages', 'title'), 'Heading');
});

test('adding a field to the schema adds it to a blank item and to saved rows', () => {
  const changed = structuredClone(schema);
  changed.screens[0].fields.push({ key: 'subtitle', label: 'Subtitle', type: 'text', column: 'meta_title' });
  const blank = makeBlankItem(changed, 'pages');
  assert.equal(blank.subtitle, '');

  const state = fromContentJson(changed, 'pages', fixture);
  state.items[0].subtitle = 'New subtitle';
  const content = toContentJson(changed, 'pages', state, fixture);
  assert.equal(content.Pages[0].meta_title, 'New subtitle');
});

test('brand name maps to the Settings site_name row', () => {
  const state = fromContentJson(schema, 'settings', fixture);
  assert.equal(state.values.site_name, 'Knots & Perl');
  state.values.site_name = 'New Brand';
  const content = toContentJson(schema, 'settings', state, fixture);
  assert.equal(content.Settings.find((row) => row.key === 'site_name').value, 'New Brand');
});

test('adding a page generates its slug and an active Pages row', () => {
  const state = fromContentJson(schema, 'pages', fixture);
  const blank = makeBlankItem(schema, 'pages');
  blank.title = 'New Page';
  state.items.push(blank);
  const content = toContentJson(schema, 'pages', state, fixture);
  const row = content.Pages.at(-1);
  assert.equal(row.slug, 'new-page');
  assert.equal(row.status, 'active');
  assert.equal(row.title, 'New Page');
});

test('adding two pages and publishing twice keeps unique ids and no ghost rows', () => {
  const state = fromContentJson(schema, 'pages', fixture);
  for (const title of ['First page', 'Second page']) {
    const item = makeBlankItem(schema, 'pages');
    item.title = title;
    state.items.push(item);
  }

  const first = toContentJson(schema, 'pages', state, fixture);
  const ids = first.Pages.map((row) => row.page_id);
  assert.equal(new Set(ids).size, ids.length);

  const second = toContentJson(schema, 'pages', state, first);
  assert.equal(second.Pages.length, first.Pages.length);
});

test('changing a picture stores the R2 key, not the public URL', () => {
  const state = fromContentJson(schema, 'pages', fixture);
  state.items[0].picture = 'assets/pages/new.jpg';
  const content = toContentJson(schema, 'pages', state, fixture);
  assert.equal(content.Pages[0].featured_image, 'assets/pages/new.jpg');
});
