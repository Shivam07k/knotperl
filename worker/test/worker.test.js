import test from 'node:test';
import assert from 'node:assert/strict';
import { handle } from '../src/index.js';
import { TAB_NAMES } from '../src/store.js';

function makeEnv(overrides = {}) {
  const store = new Map();
  const puts = [];
  const env = {
    ADMIN_PASSWORD: 'secret123',
    SESSION_SECRET: '0123456789abcdef0123456789abcdef',
    ADMIN_ORIGIN: 'https://admin.example.com',
    R2_PUBLIC_URL: 'https://img.example.com',
    GITHUB_TOKEN: 'ghp_test',
    GITHUB_REPOSITORY: 'owner/repo',
    GITHUB_WORKFLOW: 'deploy.yml',
    STATUS_SECRET: 'status-secret',
    CONTENT: {
      get: async (key) => {
        if (!store.has(key)) return null;
        const value = store.get(key);
        return { json: async () => JSON.parse(value), text: async () => value };
      },
      put: async (key, value) => {
        puts.push({ key, value });
        store.set(key, typeof value === 'string' ? value : Buffer.from(value).toString());
      },
    },
  };
  return { ...env, ...overrides, store, puts };
}

function content() {
  return Object.fromEntries(TAB_NAMES.map((tab) => [tab, []]));
}

async function login(env, password = 'secret123') {
  const response = await handle(new Request('https://worker.test/api/login', {
    method: 'POST',
    headers: { 'content-type': 'application/json', origin: env.ADMIN_ORIGIN },
    body: JSON.stringify({ password }),
  }), env);
  return response.headers.get('set-cookie')?.split(';')[0] ?? null;
}

test('wrong password is rejected and no session cookie is issued', async () => {
  const env = makeEnv();
  const cookie = await login(env, 'wrong');
  assert.equal(cookie, null);
  const response = await handle(new Request('https://worker.test/api/content', {
    headers: { cookie: '', origin: env.ADMIN_ORIGIN },
  }), env);
  assert.equal(response.status, 401);
});

test('expired session cannot read or save content', async () => {
  const env = makeEnv();
  const cookie = await login(env);
  assert.ok(cookie);
  const response = await handle(new Request('https://worker.test/api/content', {
    headers: { cookie, origin: env.ADMIN_ORIGIN },
  }), env, Date.now() + 9 * 60 * 60 * 1000);
  assert.equal(response.status, 401);
});

test('content save and read round-trip', async () => {
  const env = makeEnv();
  const cookie = await login(env);
  const data = content();
  data.Settings = [{ __row: 2, key: 'site_name', value: 'Changed' }];

  const saved = await handle(new Request('https://worker.test/api/content', {
    method: 'PUT',
    headers: { cookie, 'content-type': 'application/json', origin: env.ADMIN_ORIGIN },
    body: JSON.stringify(data),
  }), env);
  assert.equal(saved.status, 200);

  const loaded = await handle(new Request('https://worker.test/api/content', {
    headers: { cookie, origin: env.ADMIN_ORIGIN },
  }), env);
  assert.equal(loaded.status, 200);
  assert.deepEqual(await loaded.json(), data);
});

test('oversized and non-image uploads are rejected before R2 write', async () => {
  const env = makeEnv();
  const cookie = await login(env);

  const big = new FormData();
  big.append('file', new File([new Uint8Array(9 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' }));
  const bigResponse = await handle(new Request('https://worker.test/api/image', {
    method: 'POST', headers: { cookie, origin: env.ADMIN_ORIGIN }, body: big,
  }), env);
  assert.equal(bigResponse.status, 413);
  assert.equal(env.puts.length, 0);

  const wrong = new FormData();
  wrong.append('file', new File([new Uint8Array(16)], 'notes.txt', { type: 'text/plain' }));
  const wrongResponse = await handle(new Request('https://worker.test/api/image', {
    method: 'POST', headers: { cookie, origin: env.ADMIN_ORIGIN }, body: wrong,
  }), env);
  assert.equal(wrongResponse.status, 415);
  assert.equal(env.puts.length, 0);
});

test('publish without a GitHub token returns 503 and does not call GitHub', async () => {
  const env = makeEnv({ GITHUB_TOKEN: '' });
  const cookie = await login(env);
  const originalFetch = globalThis.fetch;
  let called = false;
  globalThis.fetch = async () => { called = true; return new Response(null, { status: 204 }); };
  try {
    const response = await handle(new Request('https://worker.test/api/publish', {
      method: 'POST', headers: { cookie, origin: env.ADMIN_ORIGIN },
    }), env);
    assert.equal(response.status, 503);
    assert.equal(called, false);
  } finally {
    globalThis.fetch = originalFetch;
  }
});

test('workflow can post status with the shared secret and no session', async () => {
  const env = makeEnv();
  const response = await handle(new Request('https://worker.test/api/status', {
    method: 'POST',
    headers: {
      'content-type': 'application/json',
      'x-status-secret': env.STATUS_SECRET,
      origin: env.ADMIN_ORIGIN,
    },
    body: JSON.stringify({ status: 'published', message: 'Website is live.' }),
  }), env);

  assert.equal(response.status, 200);
  assert.equal(env.store.get('publish-status.json'),
    JSON.stringify({ status: 'published', message: 'Website is live.' }));
});

test('publish requires a valid session and calls workflow_dispatch', async () => {
  const env = makeEnv();
  const originalFetch = globalThis.fetch;
  const calls = [];
  globalThis.fetch = async (url, options) => {
    calls.push({ url: String(url), options });
    return new Response(null, { status: 204 });
  };
  try {
    const denied = await handle(new Request('https://worker.test/api/publish', {
      method: 'POST', headers: { cookie: '', origin: env.ADMIN_ORIGIN },
    }), env);
    assert.equal(denied.status, 401);
    assert.equal(calls.length, 0);

    const cookie = await login(env);
    const response = await handle(new Request('https://worker.test/api/publish', {
      method: 'POST', headers: { cookie, origin: env.ADMIN_ORIGIN },
    }), env);
    assert.equal(response.status, 202);
    assert.equal(calls.length, 1);
    assert.ok(calls[0].url.includes('/repos/owner/repo/actions/workflows/deploy.yml/dispatches'));
    assert.equal(calls[0].options.method, 'POST');
  } finally {
    globalThis.fetch = originalFetch;
  }
});
