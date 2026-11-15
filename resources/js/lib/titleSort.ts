import type { MediaSummary } from '@/types/media';

/*
 * How a shelf of titles orders itself by a sort control's axis — the
 * creator pages, a franchise's: ascending, the caller turns it round.
 */

type Sortable = Pick<MediaSummary, 'title' | 'releaseDate' | 'communityRating'>;

/** Lowest rated first; a title without a rating below every rated one. */
export function byRating(a: Sortable, b: Sortable): number {
    return (a.communityRating ?? -1) - (b.communityRating ?? -1);
}

export function byTitle(a: Sortable, b: Sortable): number {
    return a.title.localeCompare(b.title);
}

/** Earliest first; a title without a date before every dated one. */
export function byRelease(a: Sortable, b: Sortable): number {
    return (a.releaseDate ?? '').localeCompare(b.releaseDate ?? '');
}
