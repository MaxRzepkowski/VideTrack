import type { UserMediaState } from '@/types/generated';
import type { ListItemEntry, UserList } from '@/types/media';

/** The system list behind "Backlog". */
export const WATCHLIST_KEY = 'watchlist';

/** The user's backlog among their lists, when they have one. */
export function backlogOf(lists: UserList[] | undefined): UserList | undefined {
    return (lists ?? []).find((list) => list.systemKey === WATCHLIST_KEY);
}

/** What the list page's panel narrows by; the search box rides along. */
export interface ListFilters {
    search: string | null;
    type: string | null;
    genre: string | null;
    /** YYYY-MM-DD, on the day the position was added. */
    from: string | null;
    to: string | null;
    hideRated: boolean;
    /** Leave out what is completed: only what is still to watch stays in view, still on the list. */
    hideCompleted: boolean;
}

/**
 * Whether a position stays in view. The user's state is asked for only when
 * a filter reads it — on a list of a thousand titles a lookup per position
 * on every keystroke in the search box is the jank. A person or a company
 * carries no state and is never hidden by it.
 */
export function matchesListFilters(item: ListItemEntry, filters: ListFilters, stateOf: () => UserMediaState): boolean {
    const term = (filters.search ?? '').trim().toLowerCase();

    if (term !== '' && !item.media.title.toLowerCase().includes(term)) {
        return false;
    }

    if (filters.type !== null && item.media.type !== filters.type) {
        return false;
    }

    if (filters.genre !== null && !item.genres.includes(filters.genre)) {
        return false;
    }

    // The list's own date axis is when the position was added — the same one
    // the "Date added" sort runs on. addedAt is ISO, so a plain string compare
    // against the date input's YYYY-MM-DD holds.
    const added = (item.addedAt ?? '').slice(0, 10);

    if (filters.from && (added === '' || added < filters.from)) {
        return false;
    }

    if (filters.to && (added === '' || added > filters.to)) {
        return false;
    }

    if (!filters.hideRated && !filters.hideCompleted) {
        return true;
    }

    const state = stateOf();

    return !(filters.hideRated && state.rating !== null) && !(filters.hideCompleted && state.watched);
}

/** The axes a list page sorts its positions by; `order` is the manual order, the only one to reorder in. */
export type ListSort = 'order' | 'added' | 'rating' | 'community' | 'release' | 'az' | 'total_runtime';

/**
 * One comparator rule for every "missing sinks to the bottom" axis: absent
 * values sort last in both directions, present ones by the axis's own compare.
 */
export function rankMissingLast<T>(a: T | null, b: T | null, dir: number, compare: (a: T, b: T) => number): number {
    if (a === null || b === null) {
        return a === b ? 0 : a === null ? 1 : -1;
    }

    return dir * compare(a, b);
}

/**
 * Positions in a list's sort, from the manual order they arrive in. Undated,
 * unrated and runtime-less positions sink whichever way the rest runs.
 */
export function sortListItems(
    items: ListItemEntry[],
    sort: ListSort,
    direction: 'asc' | 'desc',
    myRating: (item: ListItemEntry) => number | null,
): ListItemEntry[] {
    const dir = direction === 'asc' ? 1 : -1;

    switch (sort) {
        case 'order':
            return dir === 1 ? items : [...items].reverse();
        case 'added':
            // addedAt is an ISO string so it sorts chronologically.
            return [...items].sort((a, b) => dir * (a.addedAt ?? '').localeCompare(b.addedAt ?? ''));
        case 'az':
            return [...items].sort((a, b) => dir * a.media.title.localeCompare(b.media.title));
        case 'release':
            return [...items].sort((a, b) => rankMissingLast(a.media.releaseDate, b.media.releaseDate, dir, (x, y) => x.localeCompare(y)));
        case 'total_runtime':
            return [...items].sort((a, b) => rankMissingLast(a.media.totalRuntime, b.media.totalRuntime, dir, (x, y) => x - y));
        case 'community':
            return [...items].sort((a, b) => rankMissingLast(a.media.communityRating, b.media.communityRating, dir, (x, y) => x - y));
        case 'rating':
            return [...items].sort((a, b) => rankMissingLast(myRating(a), myRating(b), dir, (x, y) => x - y));
    }
}
