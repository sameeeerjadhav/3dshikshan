const CACHE_NAME = '3d-shikshan-v1.1';
const PRECACHE = ['./assets/css/style.css', './assets/icons/app-icon.svg'];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE)).catch(() => undefined)
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  // Do not intercept PHP pages to prevent redirect ERR_FAILED bugs
  const url = new URL(event.request.url);
  if (url.pathname.endsWith('.php')) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => cached || fetch(event.request))
  );
});
