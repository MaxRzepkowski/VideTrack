import { mediaImage, mediaImageSrcSet } from '@/lib/images';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

describe('mediaImage', () => {
    it('composes a TMDB path with the size on the configured CDN', () => {
        setPageProps({ imageBase: 'https://cdn.test/t/p' });

        expect(mediaImage('/abc.jpg', 'w342')).toBe('https://cdn.test/t/p/w342/abc.jpg');
        expect(mediaImage('/abc.jpg')).toBe('https://cdn.test/t/p/w500/abc.jpg');
    });

    it('passes absolute URLs of other providers through and has nothing for no path', () => {
        expect(mediaImage('https://images.igdb.com/x.jpg')).toBe('https://images.igdb.com/x.jpg');
        expect(mediaImage(null)).toBeNull();
        expect(mediaImage('')).toBeNull();
    });
});

describe('mediaImageSrcSet', () => {
    it('lists each width for a TMDB path', () => {
        expect(mediaImageSrcSet('/abc.jpg', [185, 342])).toBe(
            'https://image.tmdb.org/t/p/w185/abc.jpg 185w, https://image.tmdb.org/t/p/w342/abc.jpg 342w',
        );
    });

    it('drops the attribute for assets that cannot be resized', () => {
        expect(mediaImageSrcSet('https://images.igdb.com/x.jpg', [185])).toBeUndefined();
        expect(mediaImageSrcSet(null, [185])).toBeUndefined();
    });
});
