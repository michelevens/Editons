/*
 * Ecrivain's service worker: what makes the app installable, and a page to
 * show instead of the browser's dinosaur when a screen is opened offline.
 *
 * It deliberately caches no pages and no API responses. Pages carry
 * unpublished manuscripts and a signed-in session; a cached copy would
 * outlive a sign-out on a shared tablet. Unsaved edits are held by the open
 * editor and sent when the connection returns (ManuscriptEditor), not here.
 *
 * Only the offline page and the brand marks it uses are stored.
 */
const CACHE = 'ecrivain-shell-v1';
const OFFLINE = '/offline.html';
const SHELL = [OFFLINE, '/brand/symbol.svg', '/brand/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll(SHELL)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const { request } = event;

    // Page loads only. Everything else goes to the network untouched.
    if (request.mode !== 'navigate' || request.method !== 'GET') return;

    event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
});
