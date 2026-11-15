import DiscoverBrowse from '@/components/discover/DiscoverBrowse.vue';
import DiscoverSections from '@/components/discover/DiscoverSections.vue';
import Pagination from '@/components/Pagination.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { nth } from '@/test/lists';
import type { MediaSummary } from '@/types/media';
import { router } from '@inertiajs/vue3';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

let visits: MockInstance<typeof router.get>;
let wrapper: VueWrapper;

const global = { directives: { tooltip: vTooltip }, stubs: { MediaCard: true, CreatorCard: true } };

function browse(props: { genre?: string | null; genreItems?: MediaSummary[]; trending?: MediaSummary[] } = {}): VueWrapper {
    wrapper = mount(DiscoverBrowse, {
        props: {
            type: 'book',
            availableTypes: ['show', 'book'],
            trending: props.trending ?? [],
            popular: [],
            upcoming: [],
            genres: [{ ref: 'fantasy', name: 'Fantasy' }],
            genre: props.genre ?? null,
            genreItems: props.genreItems ?? [],
            genrePagination: props.genre ? { currentPage: 1, totalPages: 4, total: 80 } : null,
            failed: false,
        },
        global,
    });

    return wrapper;
}

/** The type and genre selects sit in the strip's filter panel, closed on arrival. */
async function openPanel(): Promise<void> {
    await wrapper.find('button[aria-expanded]').trigger('click');
}

beforeEach(() => {
    useMediaTypes();
    visits = vi.spyOn(router, 'get').mockImplementation(() => undefined);
});

afterEach(() => wrapper.unmount());

describe('DiscoverBrowse', () => {
    it('shows the sections until a genre is picked, and opens the genre listing on pick', async () => {
        browse({ trending: [mediaSummary({ type: 'book' })] });
        expect(wrapper.findComponent(DiscoverSections).exists()).toBe(true);

        await openPanel();
        const genreSelect = nth(wrapper.findAll('select'), 1);
        await genreSelect.setValue('fantasy');

        expect(visits.mock.lastCall?.[0]).toBe('/discover/book/fantasy');
    });

    it('pages the genre listing with a partial reload', () => {
        browse({ genre: 'fantasy', genreItems: [mediaSummary({ type: 'book' })] });
        expect(wrapper.findComponent(DiscoverSections).exists()).toBe(false);

        wrapper.findComponent(Pagination).vm.$emit('goto', 2);

        expect(visits.mock.lastCall?.[1]).toEqual({ type: 'book', genre: 'fantasy', page: 2 });
        expect(visits.mock.lastCall?.[2]).toMatchObject({ only: ['genreItems', 'genrePagination', 'userStates'] });
    });

    it('switching the type starts the new type unfiltered', async () => {
        browse({ genre: 'fantasy' });

        await openPanel();
        await nth(wrapper.findAll('select'), 0).setValue('show');

        expect(visits.mock.lastCall?.[0]).toBe('/discover/show');
    });

    it('says when nothing could be shown', () => {
        browse();

        expect(wrapper.text()).toContain('Nothing to show');
    });
});
