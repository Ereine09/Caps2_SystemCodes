/*
 * Service worker for the installable apps (DPS Shop, DPS Admin, DPS Staff, DPS Rider).
 * Older Chrome on Android only offers "Install app" when a page is controlled by a service worker
 * with a fetch handler. This one never serves stale pages: every request goes to the network, and
 * only when the phone is offline does a page request get the small offline page instead.
 * Registered by app/helpers/pwa_helper.php and rider/includes/bootstrap.php.
 */
const OFFLINE_CACHE = 'dps-offline-v1';
const OFFLINE_URL = new URL('offline.html', self.registration.scope).href;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(OFFLINE_CACHE).then((cache) => cache.add(OFFLINE_URL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== OFFLINE_CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    // Only page loads: everything else (APIs, images, form posts) is left to the browser as usual.
    if (req.mode !== 'navigate' || req.method !== 'GET') return;
    event.respondWith(fetch(req).catch(() => caches.match(OFFLINE_URL)));
});
