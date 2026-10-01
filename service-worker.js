const CACHE_NAME = 'isoko-ryacu-v1.2.0';
const APP_SHELL = [
  './',
  './offline.html',
  './manifest.webmanifest',
  './assets/css/style.css',
  './assets/css/components.css',
  './assets/css/admin.css',
  './assets/js/main.js',
  './assets/js/search.js',
  './assets/js/favorites.js',
  './assets/js/auth.js',
  './assets/js/admin.js',
  './assets/js/confirm-modal.js',
  './assets/images/logo/pwa-192.png',
  './assets/images/logo/pwa-512.png',
  './assets/images/logo/apple-touch-icon.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys
          .filter((key) => key.startsWith('isoko-ryacu-') && key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (url.origin !== self.location.origin || url.pathname.includes('/api/')) return;

  // HTML/navigation: always try the live site first, then the offline screen.
  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(async () => {
          return (await caches.match(request))
            || (await caches.match('./'))
            || (await caches.match('./offline.html'));
        })
    );
    return;
  }

  // Static assets: cache first for speed, refresh in the background on misses.
  if (['style', 'script', 'image', 'font'].includes(request.destination)) {
    event.respondWith(
      caches.match(request).then((cached) => {
        const network = fetch(request).then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        }).catch(() => cached);
        return cached || network;
      })
    );
  }
});
