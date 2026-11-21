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

function slugify(value) {
  return String(value).toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
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
    const item = { id: row[screen.idField] ?? row.__row, __row: row.__row };
    for (const field of screen.fields) item[field.key] = row[column(field)] ?? '';
    return item;
  });
  return { items };
}

export function makeBlankItem(schema, screenId) {
  const screen = screenFor(schema, screenId);
  const item = { id: '' };
  for (const field of screen.fields) item[field.key] = '';
  return item;
}

function newRow(screen, item, content, rowNumber, position, id) {
  const row = {
    __row: rowNumber,
    [screen.idField]: id,
    slug: slugify(item[screen.slugFrom] ?? ''),
    status: 'active',
    sort_order: String(position),
    updated_at: new Date().toISOString().slice(0, 10),
  };
  if (screen.tab === 'Pages') {
    row.page_type = 'content';
    row.template = 'custom';
  }
  if (screen.tab === 'Products') {
    row.category_id = content.Categories?.[0]?.category_id ?? '';
    row.gallery_images = '';
    row.featured = false;
    row.regular_price = '0';
    row.sale_price = '0';
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
          updated_at: new Date().toISOString().slice(0, 10),
        });
      }
    }
    return { ...content, [screen.tab]: rows };
  }

  const usedIds = new Set(rows.map((row) => String(row[screen.idField] ?? row.__row)));
  let nextRow = Math.max(0, ...rows.map((row) => Number(row.__row) || 0)) + 1;
  let position = rows.length + 1;
  const result = [];
  for (const item of state.items) {
    const existing = rows.find((row) => String(row[screen.idField] ?? row.__row) === String(item.id));
    let row;
    if (existing) {
      row = { ...existing };
    } else {
      let id;
      do {
        id = `${screen.idPrefix}${String(position++).padStart(3, '0')}`;
      } while (usedIds.has(id));
      usedIds.add(id);
      row = newRow(screen, item, content, nextRow++, position - 1, id);
      item.id = id;
      item.__row = row.__row;
    }
    for (const field of screen.fields) row[column(field)] = item[field.key] ?? '';
    result.push(row);
  }
  const keptIds = new Set(state.items.map((item) => String(item.id)));
  for (const row of rows) {
    if (!keptIds.has(row[screen.idField] ?? row.__row)) result.push(row);
  }

  return { ...content, [screen.tab]: result };
}
