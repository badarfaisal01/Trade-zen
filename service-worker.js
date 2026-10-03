const CACHE = '55ak-trade-original-ui-v4-8-4';
const CORE = [
  '/assets/css/original-trade.css',
  '/assets/css/account.css',
  '/assets/css/original-account.css',
  '/assets/js/original-trade-bridge.js',
  '/assets/js/account.js',
  '/assets/js/affiliate.js',
  '/assets/js/qrcode-matrix.js',
  '/assets/img/logo.svg',
  '/profile/images/spritemap.svg',
  '/manifest.webmanifest',
  '/trade-room/react/uikit.3cf3eedf78f32f7d0d9d.css',
  '/trade-room/react/app.1e382d4238188d8c0401.css',
  '/trade-room/react/runtime.fa9ef21ed989beb8b8b7.js',
  '/trade-room/react/uikit.944983ce32773e612c15.js',
  '/trade-room/react/vendor.ae7d8f8a004fd2feac4d.js',
  '/trade-room/react/346.3bcb3c98900d5c44aedc.js',
  '/trade-room/react/617.1c3acbb07452931d5651.js',
  '/trade-room/react/708.530efc3bf733316adac2.js',
  '/trade-room/react/app.514f7c2627cb588c0e57.js'
];

self.addEventListener('install', event => {
  event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(CORE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(key => key !== CACHE).map(key => caches.delete(key)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', event => {
  if (event.request.method !== 'GET' || new URL(event.request.url).origin !== self.location.origin) return;
  const url = new URL(event.request.url);
  if (url.pathname.includes('/api/') || url.pathname.includes('/admin/') || url.pathname.includes('/install/')) return;

  event.respondWith(fetch(event.request).then(response => {
    const copy = response.clone();
    if (response.ok && (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/trade-room/react/') || url.pathname === '/manifest.webmanifest')) {
      caches.open(CACHE).then(cache => cache.put(event.request, copy));
    }
    return response;
  }).catch(() => caches.match(event.request)));
});
