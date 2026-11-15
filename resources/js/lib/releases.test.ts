import { awaitedLabel, batchHref, compactFollowedVia, releaseBackdrop, releaseFacts, releaseStatusChip } from '@/lib/releases';
import { mediaSummary } from '@/test/factories';
import type { CalendarEntry } from '@/types/media';
import { describe, expect, it } from 'vitest';

const episode = (season: number, number: number, extra: Record<string, unknown> = {}): CalendarEntry => ({
    media: mediaSummary({
        type: 'episode',
        ref: `1399:${season}:${number}`,
        number,
        backdropPath: '/still.jpg',
        extra: { seasonNumber: season, ...extra },
    }),
    showTitle: 'Severance',
});

describe('releaseFacts', () => {
    it("says which of a film's moments a release is", () => {
        expect(releaseFacts(mediaSummary({ extra: { releaseKind: 'premiere' } }))).toEqual(['Movie release']);
        expect(releaseFacts(mediaSummary({ extra: { releaseKind: 'home' } }))).toEqual(['Outside cinemas']);
        expect(releaseFacts(mediaSummary({ extra: { releaseKind: 'both' } }))).toEqual(['Movie release', 'Outside cinemas']);
    });

    it('names a game release and its platforms, a book release alone', () => {
        expect(releaseFacts(mediaSummary({ type: 'game', extra: { platform: 'PC, PS5' } }))).toEqual(['Game release', 'PC, PS5']);
        expect(releaseFacts(mediaSummary({ type: 'game' }))).toEqual(['Game release']);
        expect(releaseFacts(mediaSummary({ type: 'book' }))).toEqual(['Book release']);
    });
});

describe('releaseStatusChip', () => {
    it('chips a special release kind, never a plain one', () => {
        expect(releaseStatusChip(mediaSummary({ extra: { releaseStatus: 'Early Access' } }))).toBe('Early Access');
        expect(releaseStatusChip(mediaSummary())).toBeNull();
    });
});

describe('compactFollowedVia', () => {
    it('keeps the first sponsor and counts the rest', () => {
        expect(compactFollowedVia(mediaSummary({ extra: { followedVia: 'Keanu Reeves, Brad Pitt, HBO' } }))).toEqual({
            full: 'Keanu Reeves, Brad Pitt, HBO',
            compact: 'Keanu Reeves +2',
        });
        expect(compactFollowedVia(mediaSummary({ extra: { followedVia: 'Keanu Reeves' } }))).toEqual({
            full: 'Keanu Reeves',
            compact: 'Keanu Reeves',
        });
        expect(compactFollowedVia(mediaSummary({ extra: { followedVia: '' } }))).toBeNull();
    });
});

describe('releaseBackdrop', () => {
    it("picks an episode's still or its show's backdrop by preference, each standing in for the other", () => {
        const withBoth = episode(1, 2, { showBackdrop: '/show.jpg' });

        expect(releaseBackdrop(withBoth, 'backdrop')).toBe('/show.jpg');
        expect(releaseBackdrop(withBoth, 'still')).toBe('/still.jpg');
        expect(releaseBackdrop(episode(1, 2), 'backdrop')).toBe('/still.jpg');
    });

    it('gives anything else its own backdrop', () => {
        expect(releaseBackdrop({ media: mediaSummary({ backdropPath: '/film.jpg' }), showTitle: null }, 'still')).toBe('/film.jpg');
    });
});

describe('batchHref', () => {
    it('leads to the season a batch fills, or the show when it straddles two', () => {
        expect(batchHref([episode(2, 1), episode(2, 2)])).toBe('/season.details/1399/2');
        expect(batchHref([episode(1, 10), episode(2, 1)])).toBe('/show.details/1399');
    });
});

describe('awaitedLabel', () => {
    it('counts who waits for a title still to come, and goes quiet once it is out', () => {
        expect(awaitedLabel(mediaSummary({ releaseDate: '2028-12-31', extra: { awaitedBy: 1289 } }))).toBe('1,289 waiting');
        expect(awaitedLabel(mediaSummary({ releaseDate: null, extra: { awaitedBy: 1 } }))).toBe('1 waiting');
        expect(awaitedLabel(mediaSummary({ releaseDate: '2020-01-01', extra: { awaitedBy: 900 } }))).toBeNull();
        expect(awaitedLabel(mediaSummary({ releaseDate: '2028-12-31' }))).toBeNull();
    });
});
