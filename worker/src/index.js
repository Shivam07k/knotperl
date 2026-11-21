import { expiredCookie, issueSession, sessionCookie, verifySession } from './auth.js';
import {
  addAudit, addMedia, bumpEpoch, getAudit, getContent, getEpoch, getHideCheck, getMedia, getSchedule, getStatus,
  getVersion, getVersionContent, listVersions, putContent, putHideCheck, putImage, putSchedule, putStatus, putVersion,
  saveVersionSnapshot, setMediaAlt,
} from './store.js';

const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
const PREVIEW_BRANCH = 'preview';

function response(body, status, env, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'content-type': 'application/json',
      'access-control-allow-origin': env.ADMIN_ORIGIN || '*',
      'access-control-allow-credentials': 'true',
      'access-control-expose-headers': 'x-content-version',
      ...headers,
    },
  });
}

function preflight(env) {
  return new Response(null, {
    status: 204,
    headers: {
      'access-control-allow-origin': env.ADMIN_ORIGIN || '*',
      'access-control-allow-credentials': 'true',
      'access-control-allow-methods': 'GET, POST, PUT, OPTIONS',
      'access-control-allow-headers': 'content-type, cookie, x-status-secret, x-expected-version',
      'access-control-expose-headers': 'x-content-version',
    },
  });
}

const forbidden = (env) => response({
  error: 'This account can only view the website. Ask an administrator to make changes.',
}, 403, env);

/** Names of the content tabs whose rows differ between two saved versions. */
function changedTabs(previous, next) {
  if (!previous) return Object.keys(next);
  return Object.keys(next).filter((tab) => JSON.stringify(previous[tab]) !== JSON.stringify(next[tab]));
}

async function dispatchWorkflow(env, inputs) {
  if (!env.GITHUB_TOKEN) return response({ error: 'Publishing is not configured yet.' }, 503, env);
  const body = inputs ? { ref: 'main', inputs } : { ref: 'main' };
  const upstream = await fetch(
    `https://api.github.com/repos/${env.GITHUB_REPOSITORY}/actions/workflows/${env.GITHUB_WORKFLOW}/dispatches`,
    {
      method: 'POST',
      headers: {
        authorization: `Bearer ${env.GITHUB_TOKEN}`,
        accept: 'application/vnd.github+json',
        'content-type': 'application/json',
        'user-agent': 'site-admin',
      },
      body: JSON.stringify(body),
    },
  );
  if (!upstream.ok) {
    const detail = (await upstream.text()).slice(0, 300);
    return response({ error: `GitHub rejected the request (${upstream.status}): ${detail}` }, 502, env);
  }
  return null;
}

export async function handle(request, env, now = Date.now()) {
  const url = new URL(request.url);
  const { pathname } = url;
  const { method } = request;
  if (method === 'OPTIONS') return preflight(env);

  const session = await verifySession(request.headers.get('cookie'), env, now);
  const authed = session !== null;
  const isAdmin = session?.role === 'admin';

  if (pathname === '/api/login' && method === 'POST') {
    let password = '';
    try {
      ({ password } = await request.json());
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    const token = await issueSession(password, env, await getEpoch(env), now);
    if (!token) return response({ error: 'Wrong password.' }, 401, env);
    return response({ ok: true }, 200, env, { 'set-cookie': sessionCookie(token) });
  }

  if (pathname === '/api/logout' && method === 'POST') {
    // Signing out ends every session, on every device. Anonymous callers cannot end anyone else's session.
    if (authed) {
      await bumpEpoch(env);
      await addAudit(env, { action: 'signed out everywhere', by: session.role });
    }
    return response({ ok: true }, 200, env, { 'set-cookie': expiredCookie() });
  }

  if (pathname === '/api/status' && method === 'POST') {
    if (!env.STATUS_SECRET || request.headers.get('x-status-secret') !== env.STATUS_SECRET) {
      return response({ error: 'Not allowed.' }, 401, env);
    }
    const status = await request.json();
    await putStatus(env, { status: String(status.status ?? 'unknown'), message: String(status.message ?? '') });
    return response({ ok: true }, 200, env);
  }

  if (!authed) return response({ error: 'Please sign in again.' }, 401, env);

  if (pathname === '/api/session' && method === 'GET') {
    return response({ role: session.role }, 200, env);
  }

  if (pathname === '/api/content' && method === 'GET') {
    const content = await getContent(env);
    if (!content) return response({ error: 'No content saved yet.' }, 404, env);
    return response(content, 200, env, { 'x-content-version': await getVersion(env) });
  }

  if (pathname === '/api/content' && method === 'PUT') {
    if (!isAdmin) return forbidden(env);
    const current = await getVersion(env);
    const expected = request.headers.get('x-expected-version') ?? '';
    if (current !== '' && expected !== current) {
      return response({
        error: 'Someone saved changes after you opened the admin. Reload the page, then make your changes again.',
      }, 409, env);
    }
    let data;
    try {
      data = await request.json();
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    const previous = await getContent(env);
    try {
      await putContent(env, data);
    } catch (error) {
      return response({ error: error.message }, 400, env);
    }
    if (previous) await saveVersionSnapshot(env, previous);
    const tabs = changedTabs(previous, data);
    if (tabs.length) await addAudit(env, { action: 'saved changes', by: session.role, tabs });
    const version = crypto.randomUUID();
    await putVersion(env, version);
    return response({ ok: true, version }, 200, env, { 'x-content-version': version });
  }

  if (pathname === '/api/history' && method === 'GET') {
    return response({ versions: await listVersions(env), audit: await getAudit(env) }, 200, env);
  }

  if (pathname === '/api/restore' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    let id = '';
    try {
      ({ id } = await request.json());
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    const restored = await getVersionContent(env, String(id));
    if (!restored) return response({ error: 'That saved version was not found.' }, 404, env);
    const previous = await getContent(env);
    await putContent(env, restored);
    // The version being replaced is kept too, so a restore can itself be undone.
    if (previous) await saveVersionSnapshot(env, previous);
    await addAudit(env, { action: 'restored an earlier version', by: session.role, from: String(id) });
    const version = crypto.randomUUID();
    await putVersion(env, version);
    return response({ ok: true, version }, 200, env, { 'x-content-version': version });
  }

  if (pathname === '/api/image' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    let file;
    try {
      file = (await request.formData()).get('file');
    } catch {
      return response({ error: 'Invalid upload.' }, 400, env);
    }
    if (!file || typeof file.arrayBuffer !== 'function') return response({ error: 'Choose a picture.' }, 400, env);
    if (file.size > MAX_IMAGE_BYTES) return response({ error: 'Picture is too large (max 8 MB).' }, 413, env);
    if (!ALLOWED_MIME.includes(file.type)) return response({ error: 'Use a JPG, PNG, WEBP or GIF picture.' }, 415, env);

    const safe = file.name.replace(/[^a-zA-Z0-9._-]/g, '-').slice(-60);
    const key = `assets/${Date.now()}-${crypto.randomUUID().slice(0, 8)}-${safe}`;
    await putImage(env, key, await file.arrayBuffer(), file.type);
    await addMedia(env, {
      key, name: file.name, alt: '', size: file.size, type: file.type, uploaded_at: new Date().toISOString(),
    });
    const base = (env.R2_PUBLIC_URL || '').replace(/\/$/, '');
    return response({ key, url: `${base}/${key}` }, 200, env);
  }

  if (pathname === '/api/publish' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    const failed = await dispatchWorkflow(env, null);
    if (failed) return failed;
    await addAudit(env, { action: 'published the website', by: session.role });
    await putStatus(env, { status: 'publishing', message: 'Publishing…' });
    return response({ status: 'publishing' }, 202, env);
  }

  if (pathname === '/api/preview' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    const failed = await dispatchWorkflow(env, { branch: PREVIEW_BRANCH });
    if (failed) return failed;
    await putStatus(env, { status: 'previewing', message: 'Building a preview. This takes a few minutes.' });
    return response({ status: 'previewing' }, 202, env);
  }

  if (pathname === '/api/status' && method === 'GET') {
    return response(await getStatus(env), 200, env);
  }

  if (pathname === '/api/media' && method === 'GET') {
    return response({ items: await getMedia(env) }, 200, env);
  }

  if (pathname === '/api/media/alt' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    let body = {};
    try {
      body = await request.json();
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    const alt = String(body.alt ?? '').trim().slice(0, 300);
    if (!(await setMediaAlt(env, String(body.key ?? ''), alt))) {
      return response({ error: 'That picture is not in the library.' }, 404, env);
    }
    return response({ ok: true }, 200, env);
  }

  if (pathname === '/api/schedule' && method === 'GET') {
    const schedule = await getSchedule(env);
    return response({ publish_at: schedule?.publish_at ?? null }, 200, env);
  }

  if (pathname === '/api/schedule' && method === 'POST') {
    if (!isAdmin) return forbidden(env);
    let body = {};
    try {
      body = await request.json();
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    if (body.publish_at === null || body.publish_at === '') {
      await putSchedule(env, null);
      await addAudit(env, { action: 'cancelled the scheduled publish', by: session.role });
      return response({ publish_at: null }, 200, env);
    }
    const when = Date.parse(String(body.publish_at));
    if (Number.isNaN(when) || when <= now) {
      return response({ error: 'Choose a time in the future.' }, 400, env);
    }
    const publishAt = new Date(when).toISOString();
    await putSchedule(env, { publish_at: publishAt });
    await addAudit(env, { action: `scheduled a publish for ${publishAt}`, by: session.role });
    return response({ publish_at: publishAt }, 200, env);
  }

  return response({ error: 'Not found.' }, 404, env);
}

/** Runs from the Worker cron. Publishes the saved content once the scheduled time has passed. */
export async function runScheduledPublish(env, now = Date.now()) {
  const schedule = await getSchedule(env);
  if (!schedule || Date.parse(schedule.publish_at) > now) return 'idle';
  await putSchedule(env, null);
  const failed = await dispatchWorkflow(env, null);
  if (failed) {
    await addAudit(env, { action: 'scheduled publish could not start', by: 'schedule' });
    return 'failed';
  }
  await addAudit(env, { action: 'published on schedule', by: 'schedule' });
  await putStatus(env, { status: 'publishing', message: 'Publishing…' });
  return 'started';
}

/** Runs from the Worker cron. Starts a publish when an item's "hide from the website on" time has passed. */
export async function runHideCheck(env, now = Date.now()) {
  const previous = await getHideCheck(env);
  await putHideCheck(env, now);
  if (!previous) return 'initialised';

  const content = await getContent(env);
  if (!content) return 'idle';
  const due = ['Pages', 'Products', 'Categories'].flatMap((tab) => (content[tab] ?? []).filter((row) => {
    const at = Date.parse(row.hide_at ?? '');
    return !Number.isNaN(at) && at > previous && at <= now;
  }));
  if (!due.length) return 'idle';

  const failed = await dispatchWorkflow(env, null);
  if (failed) {
    await addAudit(env, { action: 'hiding on schedule could not start', by: 'schedule' });
    return 'failed';
  }
  await addAudit(env, { action: `hid ${due.length} item${due.length === 1 ? '' : 's'} on schedule`, by: 'schedule' });
  await putStatus(env, { status: 'publishing', message: 'Publishing…' });
  return 'started';
}

export default {
  fetch: (request, env) => handle(request, env),
  scheduled: async (_event, env) => {
    await runScheduledPublish(env);
    await runHideCheck(env);
  },
};
