import { usePosterColumns, usePosterShelf, useShelfLimit, wholeRows } from '@/composables/useShelfLimit';
import { stubMediaQueries } from '@/test/media';
import { describe, expect, it } from 'vitest';

// The window this suite sees: sm and lg match, xl does not — four columns.
stubMediaQueries((query) => !query.includes('80rem'));

describe('poster shelves', () => {
    it('counts the columns of the widest step that matches', () => {
        expect(usePosterColumns().value).toBe(4);
        expect(usePosterColumns(4).value).toBe(3);
    });

    it('rounds a fold up to a whole row', () => {
        expect(useShelfLimit(10).value).toBe(12);
        expect(useShelfLimit(8).value).toBe(8);
    });

    it('cuts a shelf without "more" at that fold', () => {
        const items = Array.from({ length: 20 }, (_, i) => i);

        expect(usePosterShelf(10)(items)).toHaveLength(12);
        expect(usePosterShelf(10)(items.slice(0, 5))).toHaveLength(5);
    });

    it('keeps whole rows of an opened shelf, or all of a short one', () => {
        expect(wholeRows([1, 2, 3, 4, 5, 6, 7], 3)).toEqual([1, 2, 3, 4, 5, 6]);
        expect(wholeRows([1, 2], 5)).toEqual([1, 2]);
    });
});
