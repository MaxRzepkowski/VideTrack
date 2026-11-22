import { backlogOf, matchesListFilters, sortListItems, type ListFilters } from '@/lib/lists';
import { mediaSummary, userState } from '@/test/factories';
import type { ListItemEntry } from '@/types/media';
import { describe, expect, it, vi } from 'vitest';

describe('backlogOf', () => {
    it('finds the system backlog among custom lists, and nothing without one', () => {
        const backlog = { id: 5, title: 'Watchlist', systemKey: 'watchlist', itemCount: 0 };

        expect(backlogOf([{ id: 3, title: 'Favourites', systemKey: null, itemCount: 0 }, backlog])).toBe(backlog);
        expect(backlogOf([{ id: 3, title: 'Favourites', systemKey: null, itemCount: 0 }])).toBeUndefined();
        expect(backlogOf(undefined)).toBeUndefined();
    });
});

describe('matchesListFilters', () => {
    const none: ListFilters = { search: null, type: null, genre: null, from: null, to: null, hideRated: false, hideCompleted: false };

    function entry(overrides: Partial<ListItemEntry> = {}): ListItemEntry {
        return {
            id: 1,
            addedAt: '2026-03-01T10:00:00+00:00',
            genres: ['Drama'],
            mediaItemId: null,
            imdbId: null,
            media: mediaSummary({ title: 'The Matrix', type: 'movie' }),
            ...overrides,
        };
    }

    it('hides what is completed and keeps what is still to watch', () => {
        expect(matchesListFilters(entry(), { ...none, hideCompleted: true }, () => userState({ watched: true }))).toBe(false);
        expect(matchesListFilters(entry(), { ...none, hideCompleted: true }, () => userState({ watched: false, inProgress: true }))).toBe(true);
    });

    it('hides what is rated, and both together hide either', () => {
        const both = { ...none, hideRated: true, hideCompleted: true };

        expect(matchesListFilters(entry(), { ...none, hideRated: true }, () => userState({ rating: 8 }))).toBe(false);
        expect(matchesListFilters(entry(), both, () => userState({ rating: 8 }))).toBe(false);
        expect(matchesListFilters(entry(), both, () => userState({ watched: true }))).toBe(false);
        expect(matchesListFilters(entry(), both, () => userState())).toBe(true);
    });

    it('asks for the state only when a filter reads it', () => {
        const stateOf = vi.fn(() => userState({ watched: true }));

        expect(matchesListFilters(entry(), { ...none, search: 'matrix', type: 'movie', genre: 'Drama' }, stateOf)).toBe(true);
        expect(stateOf).not.toHaveBeenCalled();
    });

    it('narrows by search, type, genre and the day the position was added', () => {
        const state = () => userState();

        expect(matchesListFilters(entry(), { ...none, search: 'dune' }, state)).toBe(false);
        expect(matchesListFilters(entry(), { ...none, type: 'show' }, state)).toBe(false);
        expect(matchesListFilters(entry(), { ...none, genre: 'Comedy' }, state)).toBe(false);
        expect(matchesListFilters(entry(), { ...none, from: '2026-03-02' }, state)).toBe(false);
        expect(matchesListFilters(entry(), { ...none, to: '2026-02-28' }, state)).toBe(false);
        expect(matchesListFilters(entry(), { ...none, from: '2026-03-01', to: '2026-03-01' }, state)).toBe(true);
    });
});

describe('sortListItems', () => {
    const entry = (id: number, media: Parameters<typeof mediaSummary>[0], addedAt: string | null = null): ListItemEntry => ({
        id,
        addedAt,
        genres: [],
        mediaItemId: null,
        imdbId: null,
        media: mediaSummary(media),
    });

    const a = entry(1, { title: 'Alien', releaseDate: '1979-05-25', communityRating: 8.5 }, '2026-01-02');
    const b = entry(2, { title: 'Brazil', releaseDate: null, communityRating: null }, '2026-01-01');
    const c = entry(3, { title: 'Contact', releaseDate: '1997-07-11', communityRating: 7.5 }, '2026-01-03');
    const ids = (items: ListItemEntry[]) => items.map((item) => item.id);

    it('keeps the manual order, or runs it backwards', () => {
        expect(ids(sortListItems([a, b, c], 'order', 'asc', () => null))).toEqual([1, 2, 3]);
        expect(ids(sortListItems([a, b, c], 'order', 'desc', () => null))).toEqual([3, 2, 1]);
    });

    it('sinks what has no value, whichever way the rest runs', () => {
        expect(ids(sortListItems([a, b, c], 'release', 'asc', () => null))).toEqual([1, 3, 2]);
        expect(ids(sortListItems([a, b, c], 'release', 'desc', () => null))).toEqual([3, 1, 2]);
        expect(ids(sortListItems([a, b, c], 'community', 'desc', () => null))).toEqual([1, 3, 2]);
    });

    it("sorts by the date added, the name, and the viewer's own rating", () => {
        expect(ids(sortListItems([a, b, c], 'added', 'asc', () => null))).toEqual([2, 1, 3]);
        expect(ids(sortListItems([c, b, a], 'az', 'asc', () => null))).toEqual([1, 2, 3]);
        expect(ids(sortListItems([a, b, c], 'rating', 'desc', (item) => (item.id === 2 ? 9 : null)))).toEqual([2, 1, 3]);
    });
});
