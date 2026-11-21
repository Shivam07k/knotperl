const encoder = new TextEncoder();
const COOKIE = 'kps_session';
const EIGHT_HOURS = 8 * 60 * 60 * 1000;

function base64url(input) {
  const bytes = typeof input === 'string' ? encoder.encode(input) : new Uint8Array(input);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function fromBase64url(value) {
  const padded = value.replace(/-/g, '+').replace(/_/g, '/');
  const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
  return Uint8Array.from(binary, (char) => char.charCodeAt(0));
}

async function sign(message, secret) {
  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['sign']);
  return base64url(await crypto.subtle.sign('HMAC', key, encoder.encode(message)));
}

function cookieValue(header, name) {
  for (const part of (header ?? '').split(';')) {
    const [key, ...rest] = part.trim().split('=');
    if (key === name) return rest.join('=');
  }
  return null;
}

export async function issueSession(password, env, now = Date.now()) {
  if (!env.ADMIN_PASSWORD || password !== env.ADMIN_PASSWORD) return null;
  const payload = base64url(JSON.stringify({ exp: now + EIGHT_HOURS }));
  return `${payload}.${await sign(payload, env.SESSION_SECRET)}`;
}

export async function verifySession(cookieHeader, env, now = Date.now()) {
  const token = cookieValue(cookieHeader, COOKIE);
  if (!token) return false;
  const [payload, signature] = token.split('.');
  if (!payload || !signature || signature !== await sign(payload, env.SESSION_SECRET)) return false;
  try {
    const claims = JSON.parse(new TextDecoder().decode(fromBase64url(payload)));
    return typeof claims.exp === 'number' && claims.exp > now;
  } catch {
    return false;
  }
}

// Admin (pages.dev) and worker (workers.dev) are cross-site, so Lax/Strict would block the cookie.
export function sessionCookie(token) {
  return `${COOKIE}=${token}; Path=/; HttpOnly; Secure; SameSite=None; Max-Age=28800`;
}

export function expiredCookie() {
  return `${COOKIE}=; Path=/; HttpOnly; Secure; SameSite=None; Max-Age=0`;
}
