import { expiredCookie, issueSession, sessionCookie, verifySession } from './auth.js';
import { getContent, getStatus, putContent, putImage, putStatus } from './store.js';

const MAX_IMAGE_BYTES = 8 * 1024 * 1024;
const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

function response(body, status, env, headers = {}) {
  return new Response(JSON.stringify(body), {
    status,
    headers: {
      'content-type': 'application/json',
      'access-control-allow-origin': env.ADMIN_ORIGIN || '*',
      'access-control-allow-credentials': 'true',
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
      'access-control-allow-headers': 'content-type, cookie, x-status-secret',
    },
  });
}

export async function handle(request, env, now = Date.now()) {
  const url = new URL(request.url);
  const { pathname } = url;
  const method = request.method;
  if (method === 'OPTIONS') return preflight(env);

  const cookie = request.headers.get('cookie');
  const authed = await verifySession(cookie, env, now);

  if (pathname === '/api/login' && method === 'POST') {
    let password = '';
    try {
      ({ password } = await request.json());
    } catch {
      return response({ error: 'Invalid request.' }, 400, env);
    }
    const token = await issueSession(password, env, now);
    if (!token) return response({ error: 'Wrong password.' }, 401, env);
    return response({ ok: true }, 200, env, { 'set-cookie': sessionCookie(token) });
  }

  if (pathname === '/api/logout' && method === 'POST') {
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

  if (pathname === '/api/content' && method === 'GET') {
    const content = await getContent(env);
    return content ? response(content, 200, env) : response({ error: 'No content saved yet.' }, 404, env);
  }

  if (pathname === '/api/content' && method === 'PUT') {
    try {
      await putContent(env, await request.json());
      return response({ ok: true }, 200, env);
    } catch (error) {
      return response({ error: error.message }, 400, env);
    }
  }

  if (pathname === '/api/image' && method === 'POST') {
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
    const base = (env.R2_PUBLIC_URL || '').replace(/\/$/, '');
    return response({ key, url: `${base}/${key}` }, 200, env);
  }

  if (pathname === '/api/publish' && method === 'POST') {
    if (!env.GITHUB_TOKEN) return response({ error: 'Publishing is not configured yet.' }, 503, env);
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
        body: JSON.stringify({ ref: 'main', inputs: { note: 'Publish from admin' } }),
      },
    );
    if (!upstream.ok) return response({ error: 'Could not start publishing.' }, 502, env);
    await putStatus(env, { status: 'publishing', message: 'Publishing…' });
    return response({ status: 'publishing' }, 202, env);
  }

  if (pathname === '/api/status' && method === 'GET') {
    return response(await getStatus(env), 200, env);
  }

  return response({ error: 'Not found.' }, 404, env);
}

export default { fetch: (request, env) => handle(request, env) };
