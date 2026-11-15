import { useListBrowse } from '@/composables/useListBrowse';
import { mediaSummary, useMediaTypes, userState } from '@/test/factories';
import type { ListItemEntry } from '@/types/media';
import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick, ref } from 'vue';

beforeEach(() => useMediaTypes());

const entry = (id: number, type: string, title: string): ListItemEntry => ({
    id,
    addedAt: null,
    genres: [],
    mediaItemId: null,
    imdbId: null,
    media: mediaSummary({ type, ref: String(id), title }),
});

const items = [entry(1, 'movie', 'Alien'), entry(2, 'show', 'Andor'), entry(3, 'movie', 'Brazil')];

function browse(editing = ref(false)) {
    return useListBrowse(
        () => items,
        ref([3, 1, 2]),
        editing,
        () => userState(),
    );
}

describe('useListBrowse', () => {
    it('reads the manual order, narrowed by the search and the panel', async () => {
        const list = browse();

        expect(list.displayed.value.map((item) => item.id)).toEqual([3, 1, 2]);

        list.typeFilter.value = 'movie';
        list.searchQuery.value = 'al';
        await nextTick();

        expect(list.displayed.value.map((item) => item.id)).toEqual([1]);
        expect(list.activeFilterCount.value).toBe(1);
    });

    it('shows the whole list in its manual order while editing', async () => {
        const editing = ref(false);
        const list = browse(editing);

        list.sort.value = 'az';
        list.typeFilter.value = 'show';
        editing.value = true;
        await nextTick();

        expect(list.sort.value).toBe('order');
        expect(list.displayed.value.map((item) => item.id)).toEqual([3, 1, 2]);
    });

    it('offers the types present, in the registry order', () => {
        expect(browse().typeOptions.value).toEqual([{ type: 'show' }, { type: 'movie' }]);
    });
});
