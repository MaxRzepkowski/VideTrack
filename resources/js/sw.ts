/// <reference lib="webworker" />

import { COVER_CACHE, imageCacheFor, isSkipWaiting, OWN_IMAGE_CACHE, type ImageCache } from '@/lib/serviceWorker';
import { listenForPushes } from '@/sw/push';
import { CacheableResponsePlugin } from 'workbox-cacheable-response';
import { clientsClaim } from 'workbox-core';
import { ExpirationPlugin } from 'workbox-expiration';
import { cleanupOutdatedCaches, matchPrecache, precacheAndRoute, type PrecacheEntry } from 'workbox-precaching';
import { NavigationRoute, registerRoute } from 'workbox-routing';
import { CacheFirst, NetworkOnly, StaleWhileRevalidate } from 'workbox-strategies';

declare const self: ServiceWorkerGlobalScope & { __WB_MANIFEST: (PrecacheEntry | string)[] };

const OFFLINE_PAGE = '/offline.html';

// The build's own files and the offline page. A new deploy installs beside the old one and waits: the page asks the
// user before it takes over (resources/js/lib/pwa.ts), so a tab never runs half old, half new code.
precacheAndRoute(self.__WB_MANIFEST);
cleanupOutdatedCaches();
// The first install has nothing to replace, so it controls the open tab at once: the app never reloads by itself,
// and covers seen in that first session would otherwise not be kept.
clientsClaim();

self.addEventListener('message', (event) => {
    if (isSkipWaiting(event.data)) {
        void self.skipWaiting();
    }
});

// Notifications the instance pushes, shown even with no tab open.
listenForPushes();

// Pages always come from the network: a page carries the library's state, and an old copy of it would show
// marks that are no longer true. Without a network the offline page stands in.
registerRoute(
    new NavigationRoute(
        new NetworkOnly({
            plugins: [{ handlerDidError: async () => (await matchPrecache(OFFLINE_PAGE)) ?? Response.error() }],
        }),
    ),
);

function expiration(cache: ImageCache): ExpirationPlugin {
    return new ExpirationPlugin({ maxEntries: cache.maxEntries, maxAgeSeconds: cache.maxAgeSeconds, purgeOnQuotaError: true });
}

// A cover never changes under the same URL; the opaque answers (status 0) are kept too.
registerRoute(
    ({ request, url }) => imageCacheFor(request.destination, url, self.location.origin) === COVER_CACHE,
    new CacheFirst({
        cacheName: COVER_CACHE.name,
        plugins: [new CacheableResponsePlugin({ statuses: [0, 200] }), expiration(COVER_CACHE)],
    }),
);

// The app's own images can change under the same URL: shown from the cache, refreshed behind it.
registerRoute(
    ({ request, url }) => imageCacheFor(request.destination, url, self.location.origin) === OWN_IMAGE_CACHE,
    new StaleWhileRevalidate({ cacheName: OWN_IMAGE_CACHE.name, plugins: [expiration(OWN_IMAGE_CACHE)] }),
);
