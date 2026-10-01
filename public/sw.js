const CACHE_NAME = 'pastefilez-v2';
const STATIC_ASSETS = [
  '/assets/css/app.css',
  '/assets/js/app.js',
  '/manifest.webmanifest',
  '/assets/img/icon-192.png',
  '/assets/img/icon-512.png'
];
const CACHEABLE = /^\/assets\//;

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => Promise.allSettled(STATIC_ASSETS.map(a => cache.add(a))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (e) => {
  if (e.data === 'skip-waiting') self.skipWaiting();
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET') return;
  if (url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/api/') || url.pathname.startsWith('/hooks/') ||
      url.pathname.startsWith('/s/') || url.pathname.startsWith('/s-e2e/')) return;
  if (!CACHEABLE.test(url.pathname)) return;
  e.respondWith(
    caches.open(CACHE_NAME).then(c => c.match(req).then(r => {
      if (r) return r;
      return fetch(req).then(resp => {
        if (resp.ok) c.put(req, resp.clone());
        return resp;
      }).catch(() => new Response('', { status: 504 }));
    }))
  );
});
