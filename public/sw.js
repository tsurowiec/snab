// Minimal service worker: makes snab installable as a PWA.
// Network-first for everything — budget data must never be served stale.
// Only static build assets and icons are cached as an offline fallback.
const CACHE = 'snab-static-v1';

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const isStatic = event.request.method === 'GET'
        && url.origin === self.location.origin
        && (url.pathname.startsWith('/build/') || /\.(svg|png)$/.test(url.pathname));

    if (!isStatic) return;

    event.respondWith(
        fetch(event.request)
            .then((response) => {
                const copy = response.clone();
                caches.open(CACHE).then((cache) => cache.put(event.request, copy));
                return response;
            })
            .catch(() => caches.match(event.request)),
    );
});
