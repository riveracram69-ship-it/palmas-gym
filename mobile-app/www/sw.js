/**
 * Palma's Elite Gym - Mobile PWA Service Worker
 * Cache Strategy: Cache-First for static assets, strictly Network-Only for dynamic API & member data.
 * Zero stale data for tokens, member profile, payment sessions, or QR codes.
 */

const CACHE_NAME = 'palmas-pwa-static-v1';

const STATIC_ASSETS = [
  './manifest.json',
  './assets/images/palmas-logo.png',
  './assets/images/icon-192.png',
  './assets/images/icon-512.png',
  './assets/images/apple-touch-icon.png',
  './assets/images/gcash-logo.png',
  './assets/images/maya-logo.png',
  './assets/js/qrcode.min.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => {
      return cache.addAll(STATIC_ASSETS).catch((err) => {
        console.warn('[PWA SW] Pre-cache warning:', err);
      });
    }).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (key !== CACHE_NAME) {
            console.log('[PWA SW] Removing outdated cache:', key);
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') {
    return;
  }

  const url = new URL(req.url);

  // CRITICAL IDENTITY ISOLATION & PAYMENT SECURITY RULE:
  // NEVER cache authenticated API responses, member details, payment status, or PHP endpoints.
  const isDynamicOrSensitive = (
    url.pathname.includes('/api/') ||
    url.pathname.includes('/member/') ||
    url.pathname.endsWith('.php') ||
    req.headers.has('Authorization') ||
    url.searchParams.has('ref') ||
    url.searchParams.has('token')
  );

  if (isDynamicOrSensitive) {
    // Strictly Network-Only. Never hit CacheStorage.
    return;
  }

  // Cache static assets only (.css, .js, .png, .jpg, .svg, .woff2, .json)
  const isStatic = /\.(css|js|png|jpg|jpeg|svg|webp|woff|woff2|json)$/i.test(url.pathname);
  if (!isStatic) {
    return;
  }

  event.respondWith(
    caches.match(req).then((cached) => {
      if (cached) {
        // Return cached and refresh in background (stale-while-revalidate for static)
        fetch(req).then((networkRes) => {
          if (networkRes && networkRes.status === 200) {
            caches.open(CACHE_NAME).then((cache) => cache.put(req, networkRes));
          }
        }).catch(() => {});
        return cached;
      }

      return fetch(req).then((networkRes) => {
        if (networkRes && networkRes.status === 200 && networkRes.type === 'basic') {
          const clone = networkRes.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(req, clone));
        }
        return networkRes;
      });
    })
  );
});
