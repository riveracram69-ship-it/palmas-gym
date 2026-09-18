const CACHE_NAME = 'palmas-elite-v2';
const STATIC_ASSETS = [
    '/gym/assets/css/member.css',
    '/gym/assets/images/palmas-logo.png'
];

self.addEventListener('install', (e) => {
    e.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (e) => {
    e.waitUntil(
        caches.keys().then((keys) => {
            return Promise.all(
                keys.map((key) => {
                    // Delete all previous caches including v1 to eradicate any cached member dashboards
                    if (key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                })
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (e) => {
    if (e.request.method !== 'GET' || !e.request.url.startsWith(self.location.origin)) {
        return;
    }

    const url = new URL(e.request.url);

    // CRITICAL SECURITY RULE: NEVER cache or serve dynamic HTML / PHP pages from CacheStorage.
    // Dynamic pages (e.g. index.php, dashboard, profile) belong strictly to the authenticated user's current session.
    const isDynamicRequest = (
        url.pathname.endsWith('.php') ||
        url.pathname.includes('/api/') ||
        url.pathname.includes('/member/') ||
        e.request.mode === 'navigate' ||
        (e.request.headers.get('Accept') && e.request.headers.get('Accept').includes('text/html'))
    );

    if (isDynamicRequest) {
        // Always pass dynamic requests straight through to the network
        return;
    }

    // Static assets only (.css, .js, images, fonts)
    const isStaticAsset = /\.(css|js|png|jpg|jpeg|svg|webp|woff|woff2|ico)$/i.test(url.pathname);
    if (!isStaticAsset) {
        return;
    }

    e.respondWith(
        caches.match(e.request).then((cachedResponse) => {
            if (cachedResponse) {
                fetch(e.request).then((networkResponse) => {
                    if (networkResponse && networkResponse.status === 200) {
                        caches.open(CACHE_NAME).then((cache) => cache.put(e.request, networkResponse));
                    }
                }).catch(() => {});
                return cachedResponse;
            }

            return fetch(e.request).then((networkResponse) => {
                if (networkResponse && networkResponse.status === 200) {
                    const responseClone = networkResponse.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(e.request, responseClone));
                }
                return networkResponse;
            });
        })
    );
});
