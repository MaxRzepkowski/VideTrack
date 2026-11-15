import { lightboxOrigin, useLightbox } from '@/composables/useLightbox';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetched: string[] = [];

beforeEach(() => {
    fetched.length = 0;
    vi.stubGlobal(
        'Image',
        class {
            set src(url: string) {
                fetched.push(url);
            }
        },
    );
});

afterEach(() => vi.unstubAllGlobals());

const FROM_PREVIEW = lightboxOrigin('w780', 'landscape');
const PHOTOS = ['/a.jpg', '/b.jpg', '/c.jpg', '/d.jpg'];

describe('the lightbox', () => {
    it('opens on the image clicked, its thumbnail under the full one', () => {
        const lightbox = useLightbox();

        lightbox.open(PHOTOS, '/c.jpg', FROM_PREVIEW);

        expect(lightbox.current.value).toBe('/c.jpg');
        expect(lightbox.thumbSrc.value).toBe('https://image.tmdb.org/t/p/w780/c.jpg');
        expect(lightbox.fullSrc.value).toBe('https://image.tmdb.org/t/p/original/c.jpg');
    });

    it('fetches the images either side ahead, and again after every step', () => {
        const lightbox = useLightbox();

        lightbox.open(PHOTOS, '/b.jpg', FROM_PREVIEW);
        expect(fetched).toEqual(['https://image.tmdb.org/t/p/original/c.jpg', 'https://image.tmdb.org/t/p/original/a.jpg']);

        fetched.length = 0;
        lightbox.step(1);
        expect(fetched).toEqual(['https://image.tmdb.org/t/p/original/d.jpg', 'https://image.tmdb.org/t/p/original/b.jpg']);
    });

    it('goes round from the last image to the first and back', () => {
        const lightbox = useLightbox();

        lightbox.open(PHOTOS, '/d.jpg', FROM_PREVIEW);
        lightbox.step(1);
        expect(lightbox.current.value).toBe('/a.jpg');

        lightbox.step(-1);
        expect(lightbox.current.value).toBe('/d.jpg');
    });

    it('fetches one neighbour of two images once, and none of a single one', () => {
        const lightbox = useLightbox();

        lightbox.open(['/a.jpg', '/b.jpg'], '/a.jpg', FROM_PREVIEW);
        expect(fetched).toEqual(['https://image.tmdb.org/t/p/original/b.jpg']);

        fetched.length = 0;
        lightbox.open(['/a.jpg'], '/a.jpg', FROM_PREVIEW);
        expect(fetched).toEqual([]);
    });

    it('fetches nothing once closed', () => {
        const lightbox = useLightbox();

        lightbox.open(PHOTOS, '/a.jpg', FROM_PREVIEW);
        lightbox.close();
        fetched.length = 0;
        lightbox.step(1);

        expect(lightbox.current.value).toBeNull();
        expect(fetched).toEqual([]);
    });

    it('passes an address another provider serves whole as it is', () => {
        const lightbox = useLightbox();

        lightbox.open(['https://images.igdb.com/t_1080p/x.jpg'], 'https://images.igdb.com/t_1080p/x.jpg', {
            thumbSize: 'w500',
            width: 1000,
            height: 1500,
        });

        expect([lightbox.thumbSrc.value, lightbox.fullSrc.value]).toEqual([
            'https://images.igdb.com/t_1080p/x.jpg',
            'https://images.igdb.com/t_1080p/x.jpg',
        ]);
    });
});
