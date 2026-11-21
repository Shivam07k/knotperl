export const TAB_NAMES = [
  'Settings', 'Pages', 'Products', 'Sections', 'Navigation', 'Categories',
  'Banners', 'Testimonials', 'Services', 'SEO', 'Contact', 'Redirects',
];

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

export async function putImage(env, key, bytes, mime) {
  await env.CONTENT.put(key, bytes, { httpMetadata: { contentType: mime } });
}

export async function getStatus(env) {
  const object = await env.CONTENT.get('publish-status.json');
  return object ? await object.json() : { status: 'never', message: 'Not published yet.' };
}

export async function putStatus(env, status) {
  await env.CONTENT.put('publish-status.json', JSON.stringify(status));
}
