import ActivityPanel from '@/components/users/ActivityPanel.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { ActivityEntry, ActivityFilters } from '@/types/media';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({ router: { get: vi.fn() } }));

const filters = (overrides: Partial<ActivityFilters> = {}): ActivityFilters => ({
    sort: 'activity',
    dir: 'desc',
    q: null,
    group: 'grid',
    genre: null,
    type: null,
    status: null,
    from: null,
    to: null,
    hideRated: false,
    hideListed: false,
    byCompletions: false,
    ...overrides,
});

const entry = (overrides: Partial<ActivityEntry> = {}): ActivityEntry => ({
    id: null,
    at: '2026-10-01T20:00:00Z',
    media: mediaSummary({ title: 'Heat' }),
    day: null,
    rating: null,
    outcome: null,
    completions: null,
    ...overrides,
});

function panel(props: { filters: ActivityFilters; entries?: ActivityEntry[]; withCompletions?: boolean }) {
    return mount(ActivityPanel, {
        props: {
            routeName: 'users.history',
            username: 'alex',
            isOwner: true,
            entries: [entry()],
            pagination: null,
            genreOptions: [],
            typeOptions: [],
            sortLabel: 'Mark date',
            withStatus: true,
            ...props,
        },
        global: {
            directives: { tooltip: vTooltip },
            stubs: {
                MediaCard: true,
                SearchInput: true,
                TypeFilter: true,
                PageToolbar: { template: '<div><slot name="filters" /><slot name="sort" /><slot name="view" /></div>' },
            },
        },
    });
}

const sortValues = (wrapper: ReturnType<typeof panel>): string[] => wrapper.findAll('option').map((option) => option.attributes('value') ?? '');

beforeEach(() => {
    useMediaTypes();
});

describe('ActivityPanel', () => {
    it('offers times finished only on the panel that counts it', () => {
        expect(sortValues(panel({ filters: filters(), withCompletions: true }))).toContain('completions');
        expect(sortValues(panel({ filters: filters() }))).not.toContain('completions');
    });

    it('captions a title with how many times it was finished', () => {
        const wrapper = panel({
            filters: filters({ sort: 'completions', byCompletions: true }),
            entries: [entry({ completions: 3 })],
            withCompletions: true,
        });

        expect(wrapper.text()).toContain('3×');
        expect(wrapper.text()).toContain('Finished 3 times');
    });

    it('starts a newly picked axis in its natural direction', async () => {
        const wrapper = panel({ filters: filters({ sort: 'title', dir: 'asc' }), withCompletions: true });

        await wrapper.find('select[aria-label="Sort"]').setValue('completions');

        expect(router.get).toHaveBeenCalledWith(expect.any(String), { group: 'grid', sort: 'completions' }, expect.anything());
    });

    it('drops the completed or dropped filter while counting completions, and only then', () => {
        const counting = panel({ filters: filters({ sort: 'completions', status: 'dropped', byCompletions: true }), withCompletions: true });
        const timeline = panel({ filters: filters({ sort: 'completions', group: 'day' }), withCompletions: true });

        expect(counting.text()).not.toContain('Status');
        expect(timeline.text()).toContain('Status');
    });
});
