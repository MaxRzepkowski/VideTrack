import { COVER_CACHE, imageCacheFor, isSkipWaiting, OWN_IMAGE_CACHE } from '@/lib/serviceWorker';
import { describe, expect, it } from 'vitest';

const origin = 'https://tracker.example.com';

describe('imageCacheFor', () => {
    it('keeps images from elsewhere as covers and the app’s own apart', () => {
        expect(imageCacheFor('image', new URL('https://image.tmdb.org/t/p/w342/a.jpg'), origin)).toBe(COVER_CACHE);
        expect(imageCacheFor('image', new URL(`${origin}/storage/avatars/alex.png`), origin)).toBe(OWN_IMAGE_CACHE);
    });

    it('leaves pages, visits and library requests to the network', () => {
        expect(imageCacheFor('document', new URL(`${origin}/dashboard`), origin)).toBeNull();
        expect(imageCacheFor('', new URL(`${origin}/ui/v1/library/plays`), origin)).toBeNull();
        expect(imageCacheFor('', new URL('https://image.tmdb.org/t/p/w342/a.jpg'), origin)).toBeNull();
    });
});

describe('isSkipWaiting', () => {
    it('takes only the message workbox-window posts for an update', () => {
        expect(isSkipWaiting({ type: 'SKIP_WAITING' })).toBe(true);
        expect(isSkipWaiting({ type: 'GET_VERSION' })).toBe(false);
        expect(isSkipWaiting('SKIP_WAITING')).toBe(false);
        expect(isSkipWaiting(null)).toBe(false);
    });
});
