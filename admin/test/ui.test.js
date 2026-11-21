import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

test('admin shell shows only simple controls and gets screen names from the schema', () => {
  const html = readFileSync(new URL('../index.html', import.meta.url), 'utf8');
  const schema = JSON.parse(readFileSync(new URL('../schema.json', import.meta.url), 'utf8'));
  for (const label of ['Publish', 'Change picture', 'Download backup']) {
    assert.ok(html.includes(label), `missing ${label}`);
  }
  assert.deepEqual(schema.screens.map((screen) => screen.label), ['Pages', 'Products', 'Settings']);
  assert.doesNotMatch(html, /slug|json|\br2\b|token|spreadsheet/i);
});
