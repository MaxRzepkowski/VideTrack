import DiscoverPeople from '@/components/discover/DiscoverPeople.vue';
import Pagination from '@/components/Pagination.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { router } from '@inertiajs/vue3';
import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from 'vitest';

let visits: MockInstance<typeof router.get>;
let wrapper: VueWrapper;

const global = { directives: { tooltip: vTooltip }, stubs: { CreatorCard: true } };

beforeEach(() => {
    useMediaTypes();
    visits = vi.spyOn(router, 'get').mockImplementation(() => undefined);
});

afterEach(() => wrapper.unmount());

describe('DiscoverPeople', () => {
    it('counts and pages the people grid, and says when a page failed', () => {
        wrapper = mount(DiscoverPeople, {
            props: {
                availableTypes: ['show', 'person'],
                people: [mediaSummary({ type: 'person' })],
                peoplePagination: { currentPage: 1, totalPages: 3, total: 60 },
                failed: true,
            },
            global,
        });

        expect(wrapper.text()).toContain('60 items');
        expect(wrapper.find('[role="alert"]').exists()).toBe(true);

        wrapper.findComponent(Pagination).vm.$emit('goto', 2);

        expect(visits.mock.lastCall?.[0]).toBe('/discover/person/2');
    });

    it('says when there is nobody to show', () => {
        wrapper = mount(DiscoverPeople, {
            props: { availableTypes: ['person'], people: [], peoplePagination: { currentPage: 1, totalPages: 1, total: 0 }, failed: false },
            global,
        });

        expect(wrapper.text()).toContain('Nothing to show');
        expect(wrapper.findComponent(Pagination).exists()).toBe(false);
    });
});
