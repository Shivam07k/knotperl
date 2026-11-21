import { getEpoch } from './store.js';

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

export async function issueSession(password, env, epoch, now = Date.now()) {
  const role = roleFor(password, env);
  if (!role) return null;
  const payload = base64url(JSON.stringify({ exp: now + EIGHT_HOURS, role, epoch }));
  return `${payload}.${await sign(payload, env.SESSION_SECRET)}`;
}

/** Returns the signed-in role ('admin' or 'viewer'), or null when the session is missing, expired, or revoked. */
export async function verifySession(cookieHeader, env, now = Date.now()) {
  const token = cookieValue(cookieHeader, COOKIE);
  if (!token) return null;
  const [payload, signature] = token.split('.');
  if (!payload || !signature || signature !== await sign(payload, env.SESSION_SECRET)) return null;
  try {
    const claims = JSON.parse(new TextDecoder().decode(fromBase64url(payload)));
    if (typeof claims.exp !== 'number' || claims.exp <= now) return null;
    if (claims.epoch !== await getEpoch(env)) return null;
    return { role: claims.role === 'viewer' ? 'viewer' : 'admin' };
  } catch {
    return null;
  }
}

function roleFor(password, env) {
  if (env.ADMIN_PASSWORD && password === env.ADMIN_PASSWORD) return 'admin';
  if (env.VIEWER_PASSWORD && password === env.VIEWER_PASSWORD) return 'viewer';
  return null;
}


// Admin (pages.dev) and worker (workers.dev) are cross-site, so Lax/Strict would block the cookie.
export function sessionCookie(token) {
  return `${COOKIE}=${token}; Path=/; HttpOnly; Secure; SameSite=None; Max-Age=28800`;
}

export function expiredCookie() {
  return `${COOKIE}=; Path=/; HttpOnly; Secure; SameSite=None; Max-Age=0`;
}
