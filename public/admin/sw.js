// Service worker for the officer back-office.
//
// It exists so the back-office can be installed to a phone's home screen. It
// deliberately caches ONLY the manifest and the icons: officer pages are
// authenticated and officer phones are shared, so no HTML or API response is
// ever stored on the device. Everything else goes straight to the network —
// there is no offline data entry (OCR, fee lookup and verification all need
// the server).
const CACHE = 'landfee-admin-v1';
const SHELL = [
  './manifest.webmanifest',
  './icon-192.png',
  './icon-512.png',
  './icon-maskable-512.png',
  './apple-touch-icon.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()),
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim()),
  );
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);
  const isIcon = url.origin === location.origin && /^\/.*\/(icon-(192|512|maskable-512)|apple-touch-icon)\.png$/.test(url.pathname);
  const isManifest = url.origin === location.origin && url.pathname.endsWith('/manifest.webmanifest');
  if (event.request.method !== 'GET' || (!isIcon && !isManifest)) {
    return; // never intercept pages, API calls or uploads
  }
  event.respondWith(
    caches.open(CACHE).then(async (c) => {
      const hit = await c.match(event.request);
      const net = fetch(event.request).then((r) => {
        if (r.ok) c.put(event.request, r.clone());
        return r;
      }).catch(() => hit);
      return hit || net;
    }),
  );
});
