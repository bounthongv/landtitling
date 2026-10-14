/* Service worker — offline-first for citizen PWA */
const CACHE = 'landfee-pwa-v1';
const ASSETS = [
  '/LandTitling/public/citizen/',
  '/LandTitling/public/citizen/lookup.php',
  '/LandTitling/public/citizen/quote.php',
  '/LandTitling/public/citizen/payments.php',
  '/LandTitling/public/citizen/payment-view.php',
  '/LandTitling/public/citizen/manifest.json',
];

self.addEventListener('install', (e) => {
  e.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (e) => {
  e.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (e) => {
  e.respondWith(
    fetch(e.request).catch(() => caches.match(e.request))
  );
});
