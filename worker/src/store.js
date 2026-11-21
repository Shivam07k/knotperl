export const TAB_NAMES = [
  'Settings', 'Pages', 'Products', 'Sections', 'Navigation', 'Categories',
  'Banners', 'Testimonials', 'Services', 'SEO', 'Contact', 'Redirects',
];

const EPOCH_KEY = 'session-epoch.txt';
const AUDIT_KEY = 'audit-log.json';
const MEDIA_KEY = 'media.json';
const SCHEDULE_KEY = 'schedule.json';
const HISTORY_PREFIX = 'history/';
const HISTORY_KEEP = 50;
const AUDIT_KEEP = 200;

export async function getContent(env) {
  const object = await env.CONTENT.get('content.json');
  return object ? await object.json() : null;
}

export async function putContent(env, data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new Error('Content must be an object.');
  }
  for (const tab of TAB_NAMES) {
    if (!Array.isArray(data[tab])) throw new Error(`Missing content section: ${tab}.`);
  }
  await env.CONTENT.put('content.json', JSON.stringify(data));
}

/** Changes every time content is saved. Editors send the version they loaded so stale saves are refused. */
export async function getVersion(env) {
  const object = await env.CONTENT.get('content-version.txt');
  return object ? await object.text() : '';
}

export async function putVersion(env, version) {
  await env.CONTENT.put('content-version.txt', version);
}

/** Bumping the epoch invalidates every session token issued before the bump. */
export async function getEpoch(env) {
  const object = await env.CONTENT.get(EPOCH_KEY);
  return object ? await object.text() : '0';
}

export async function bumpEpoch(env) {
  const next = String(Number(await getEpoch(env)) + 1);
  await env.CONTENT.put(EPOCH_KEY, next);
  return next;
}

export async function getAudit(env) {
  const object = await env.CONTENT.get(AUDIT_KEY);
  return object ? await object.json() : [];
}

export async function addAudit(env, entry) {
  const log = [{ ...entry, at: new Date().toISOString() }, ...(await getAudit(env))].slice(0, AUDIT_KEEP);
  await env.CONTENT.put(AUDIT_KEY, JSON.stringify(log));
}

/** Stores the content as it was before a save, so it can be restored later. Keeps the newest versions only. */
export async function saveVersionSnapshot(env, content) {
  const key = `${HISTORY_PREFIX}${Date.now()}-${crypto.randomUUID().slice(0, 8)}.json`;
  await env.CONTENT.put(key, JSON.stringify({ content }));
  const listing = await env.CONTENT.list({ prefix: HISTORY_PREFIX });
  const keys = listing.objects.map((object) => object.key).sort().reverse();
  for (const old of keys.slice(HISTORY_KEEP)) await env.CONTENT.delete(old);
}

export async function listVersions(env) {
  const listing = await env.CONTENT.list({ prefix: HISTORY_PREFIX });
  return listing.objects
    .map((object) => object.key)
    .sort()
    .reverse()
    .map((id) => ({
      id,
      saved_at: new Date(Number(id.slice(HISTORY_PREFIX.length).split('-')[0])).toISOString(),
    }));
}

export async function getVersionContent(env, id) {
  if (!id.startsWith(HISTORY_PREFIX) || id.includes('..')) return null;
  const object = await env.CONTENT.get(id);
  if (!object) return null;
  const parsed = await object.json();
  return parsed.content ?? null;
}

export async function putImage(env, key, bytes, mime) {
  await env.CONTENT.put(key, bytes, { httpMetadata: { contentType: mime } });
}

/** Library entries for uploaded pictures. Each one holds the alt text used for accessibility. */
export async function getMedia(env) {
  const object = await env.CONTENT.get(MEDIA_KEY);
  return object ? await object.json() : [];
}

export async function addMedia(env, item) {
  const list = await getMedia(env);
  list.unshift(item);
  await env.CONTENT.put(MEDIA_KEY, JSON.stringify(list));
}

export async function setMediaAlt(env, key, alt) {
  const list = await getMedia(env);
  const item = list.find((entry) => entry.key === key);
  if (!item) return false;
  item.alt = alt;
  await env.CONTENT.put(MEDIA_KEY, JSON.stringify(list));
  return true;
}

/** A publish scheduled for a future time. Null when nothing is scheduled. */
export async function getSchedule(env) {
  const object = await env.CONTENT.get(SCHEDULE_KEY);
  return object ? await object.json() : null;
}

export async function putSchedule(env, schedule) {
  if (schedule) await env.CONTENT.put(SCHEDULE_KEY, JSON.stringify(schedule));
  else await env.CONTENT.delete(SCHEDULE_KEY);
}

export async function getStatus(env) {
  const object = await env.CONTENT.get('publish-status.json');
  return object ? await object.json() : { status: 'never', message: 'Not published yet.' };
}

export async function putStatus(env, status) {
  await env.CONTENT.put('publish-status.json', JSON.stringify(status));
}
