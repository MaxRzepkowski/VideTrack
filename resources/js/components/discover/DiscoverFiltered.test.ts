import DiscoverFiltered from '@/components/discover/DiscoverFiltered.vue';
import DiscoverFilters from '@/components/discover/DiscoverFilters.vue';
import MediaCardSkeleton from '@/components/media/MediaCardSkeleton.vue';
import Pagination from '@/components/Pagination.vue';
import SortControl from '@/components/SortControl.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { nth } from '@/test/lists';
import type { DiscoverActiveFilters, DiscoverFilter } from '@/types/media';
import { router } from '@inertiajs/vue3';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

const filters: DiscoverFilter[] = [
    {
        key: 'genres',
        label: 'Genres',
        kind: 'multi_select',
        options: [{ value: '18', label: 'Drama' }],
        default: null,
        unavailable: false,
        open: false,
        rangeMin: null,
        rangeMax: null,
    },
    {
        key: 'sort',
        label: 'Sort by',
        kind: 'select',
        options: [
            { value: 'popularity.desc', label: 'Popularity' },
            { value: 'first_air_date.desc', label: 'Air date', ascending: 'first_air_date.asc' },
        ],
        default: 'popularity.desc',
        unavailable: false,
        open: false,
        rangeMin: null,
        rangeMax: null,
    },
];

let visits: MockInstance<typeof router.get>;
let wrapper: VueWrapper;

function render(activeFilters: DiscoverActiveFilters = {}): VueWrapper {
    wrapper = mount(DiscoverFiltered, {
        props: {
            type: 'show',
            availableTypes: ['show', 'movie'],
            trending: [],
            popular: [],
            upcoming: [],
            filters,
            activeFilters,
            filterCount: Object.keys(activeFilters).length,
            filteredItems: [],
            filteredPagination: null,
            failed: false,
        },
        global: {
            directives: { tooltip: vTooltip },
            stubs: {
                DiscoverSections: true,
                Dialog: { template: '<div><slot /></div>' },
                DialogScrollContent: { template: '<div><slot /></div>' },
                DialogHeader: { template: '<div><slot /></div>' },
                DialogTitle: { template: '<div><slot /></div>' },
                DialogFooter: { template: '<div><slot /></div>' },
            },
        },
    });

    return wrapper;
}

/** The `data` of the last discover visit. */
const lastQuery = () => visits.mock.lastCall?.[1];

beforeEach(() => {
    useMediaTypes();
    visits = vi.spyOn(router, 'get').mockImplementation(() => undefined);
});

afterEach(() => wrapper.unmount());

describe('DiscoverFiltered', () => {
    it('splits a directional sort into an axis and a direction, and joins them back', async () => {
        render();
        const sort = wrapper.findComponent(SortControl);

        sort.vm.$emit('update:sort', 'first_air_date.desc');
        expect(lastQuery()).toEqual({ type: 'show', f: { sort: 'first_air_date.desc' } });

        await wrapper.setProps({ activeFilters: { sort: 'first_air_date.desc' } });
        expect(sort.props('sort')).toBe('first_air_date.desc');
        expect(sort.props('dir')).toBe('desc');

        sort.vm.$emit('update:dir', 'asc');
        expect(lastQuery()).toEqual({ type: 'show', f: { sort: 'first_air_date.asc' } });
    });

    it('treats the resting order as no filter at all', () => {
        render({ sort: 'first_air_date.desc' });

        wrapper.findComponent(SortControl).vm.$emit('update:sort', 'popularity.desc');

        // An empty set brings the browse sections back: a full visit, no `f`.
        expect(lastQuery()).toEqual({ type: 'show' });
        expect(visits.mock.lastCall?.[2]).toMatchObject({ only: [] });
    });

    it('applies the narrow-screen draft only when asked', async () => {
        render();
        const dialogFilters = nth(wrapper.findAllComponents(DiscoverFilters), 1);

        dialogFilters.vm.$emit('update', 'genres', ['18']);
        expect(visits).not.toHaveBeenCalled();

        const apply = wrapper.findAll('button').find((button) => button.text() === 'Show results');
        await apply?.trigger('click');

        expect(lastQuery()).toEqual({ type: 'show', f: { genres: ['18'] } });
    });

    it('keeps every filter it was given when another one changes', () => {
        render({ genres: ['18'] });

        wrapper.findComponent(SortControl).vm.$emit('update:sort', 'first_air_date.desc');

        expect(lastQuery()).toEqual({ type: 'show', f: { genres: ['18'], sort: 'first_air_date.desc' } });
    });

    it('shows skeletons while a visit is in the air, then the grid and its pager', async () => {
        render({ genres: ['18'] });
        expect(wrapper.text()).toContain('Nothing to show');

        wrapper.findComponent(SortControl).vm.$emit('update:sort', 'first_air_date.desc');
        visits.mock.lastCall?.[2]?.onStart?.({} as never);
        await wrapper.vm.$nextTick();
        expect(wrapper.findAllComponents(MediaCardSkeleton)).toHaveLength(20);

        visits.mock.lastCall?.[2]?.onFinish?.({} as never);
        await wrapper.setProps({
            filteredItems: [mediaSummary()],
            filteredPagination: { currentPage: 1, totalPages: 2, total: 21 },
        });

        expect(wrapper.findAllComponents(MediaCardSkeleton)).toHaveLength(0);
        expect(wrapper.text()).toContain('21 items');

        wrapper.findComponent(Pagination).vm.$emit('goto', 2);
        expect(lastQuery()).toEqual({ type: 'show', f: { genres: ['18'], sort: 'first_air_date.desc' }, page: 2 });
    });

    it('offers no filters while their dictionary is down', () => {
        wrapper = mount(DiscoverFiltered, {
            props: {
                type: 'show',
                availableTypes: ['show'],
                trending: [],
                popular: [],
                upcoming: [],
                filters: [],
                activeFilters: {},
                filterCount: 0,
                filteredItems: [],
                filteredPagination: null,
                failed: true,
            },
            global: { directives: { tooltip: vTooltip }, stubs: { DiscoverSections: true } },
        });

        expect(wrapper.text()).toContain('Temporarily unavailable');
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);
    });
});
