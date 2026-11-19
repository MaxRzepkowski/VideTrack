// What the service worker (resources/js/sw.ts) keeps and which messages it takes, apart from the worker's wiring so
// it can be tested in a page.

const DAY_SECONDS = 24 * 60 * 60;

export type ImageCache = { name: string; maxEntries: number; maxAgeSeconds: number };

/**
 * Covers and stills from the providers' CDNs. They arrive opaque (no CORS), and the browser books each opaque answer
 * at several megabytes against the quota, so the count stays at what a few weeks of browsing show.
 */
export const COVER_CACHE: ImageCache = { name: 'covers', maxEntries: 400, maxAgeSeconds: 30 * DAY_SECONDS };

/** The app's own images: avatars and channel logos, far fewer than the covers. */
export const OWN_IMAGE_CACHE: ImageCache = { name: 'own-images', maxEntries: 200, maxAgeSeconds: 30 * DAY_SECONDS };

/**
 * The cache an image request belongs to, or null for anything that is not an image. Pages, Inertia's visits and the
 * library's reads and writes are never images, so they always reach the network.
 */
export function imageCacheFor(destination: RequestDestination, url: URL, origin: string): ImageCache | null {
    if (destination !== 'image') {
        return null;
    }

    return url.origin === origin ? OWN_IMAGE_CACHE : COVER_CACHE;
}

/** The page's answer to "a new version is ready": workbox-window posts it when the user takes the update. */
export function isSkipWaiting(message: unknown): boolean {
    return typeof message === 'object' && message !== null && 'type' in message && message.type === 'SKIP_WAITING';
}
