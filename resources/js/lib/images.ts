import { imageBase } from '@/lib/pageContext';

// w1920 is absent from TMDB's /configuration list yet the CDN serves it, always
// at exactly 1920x1080 for backdrops. It is the only step between w1280 and
// `original`, which is unbounded (3840x2160 files are common) — w1440 really is
// rejected with a 400, so the CDN's set is wider than the documented one but
// not arbitrary.
export type ImageSize = 'w92' | 'w185' | 'w342' | 'w500' | 'w780' | 'w1280' | 'w1920' | 'original';

/**
 * Full CDN URL for a media image. TMDB paths are composed with the requested
 * size; other providers (IGDB) serve absolute URLs with the size baked in —
 * those pass through unchanged.
 */
export function mediaImage(path: string | null | undefined, size: ImageSize = 'w500'): string | null {
    if (!path) {
        return null;
    }

    if (path.startsWith('http')) {
        return path;
    }

    return `${imageBase()}/${size}${path}`;
}

/**
 * A `srcset` string for a TMDB image at several widths, so the browser fetches
 * the size that fits the rendered box instead of always the largest (a big
 * mobile-payload win on multi-column grids). Returns undefined for non-TMDB
 * assets (IGDB/absolute URLs bake the size in and can't be resized) — bound to
 * `:srcset`, undefined simply drops the attribute and the `src` stands alone.
 */
export function mediaImageSrcSet(path: string | null | undefined, widths: number[]): string | undefined {
    if (!path || path.startsWith('http')) {
        return undefined;
    }

    const base = imageBase();

    return widths.map((w) => `${base}/w${w}${path} ${w}w`).join(', ');
}
