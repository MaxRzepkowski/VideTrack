import MediaGrid from '@/components/media/MediaGrid.vue';
import ActivityPanel from '@/components/users/ActivityPanel.vue';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { ActivitySort } from '@/types/generated';
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

const HISTORY_SORTS: ActivitySort[] = ['activity', 'added', 'my_rating', 'community', 'title', 'release', 'runtime', 'completions'];

function panel(props: {
    filters: ActivityFilters;
    entries?: ActivityEntry[];
    sortOptions?: ActivitySort[];
    ownerRatingsShown?: boolean;
    isOwner?: boolean;
    removeLabel?: string | null;
}) {
    return mount(ActivityPanel, {
        props: {
            routeName: 'users.history',
            username: 'alex',
            ownerName: 'Alex',
            isOwner: true,
            entries: [entry()],
            pagination: null,
            genreOptions: [],
            typeOptions: [],
            sortLabel: 'Mark date',
            withStatus: true,
            sortOptions: HISTORY_SORTS,
            ownerRatingsShown: false,
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

const sortSelect = (wrapper: ReturnType<typeof panel>) => wrapper.find('select[aria-label="Sort"]');

const cards = (wrapper: ReturnType<typeof panel>): string => wrapper.findComponent(MediaGrid).text();

beforeEach(() => {
    useMediaTypes();
    vi.clearAllMocks();
});

describe('ActivityPanel', () => {
    it('lists the axes the server offers, each by its name', () => {
        const options = sortSelect(panel({ filters: filters(), sortOptions: ['activity', 'owner_rating', 'completions'], isOwner: false }))
            .findAll('option')
            .map((option) => option.text());

        expect(options).toEqual(['Mark date', 'Alex’s rating', 'Times finished']);
    });

    it('captions a title with how many times it was finished', () => {
        const wrapper = panel({ filters: filters({ sort: 'completions', byCompletions: true }), entries: [entry({ completions: 3 })] });

        expect(wrapper.text()).toContain('3×');
        expect(wrapper.text()).toContain('Finished 3 times');
    });

    it('starts a newly picked axis in its natural direction', async () => {
        const wrapper = panel({ filters: filters({ sort: 'title', dir: 'asc' }) });

        await sortSelect(wrapper).setValue('completions');

        expect(router.get).toHaveBeenCalledWith(expect.any(String), { group: 'grid', sort: 'completions' }, expect.anything());
    });

    it('drops the completed or dropped filter while counting completions, and only then', () => {
        const counting = panel({ filters: filters({ sort: 'completions', status: 'dropped', byCompletions: true }) });
        const timeline = panel({ filters: filters({ sort: 'completions', group: 'day' }) });

        expect(counting.text()).not.toContain('Status');
        expect(timeline.text()).toContain('Status');
    });

    it('tells a visitor the hide toggles judge their own library', () => {
        const visitor = panel({ filters: filters(), isOwner: false });

        expect(visitor.text()).toContain('Hide rated by me');
        expect(visitor.text()).toContain('Hide on my lists');

        const owner = panel({ filters: filters() });
        expect(owner.text()).toContain('Hide rated');
        expect(owner.text()).not.toContain('by me');
        expect(owner.text()).not.toContain('my lists');
    });

    it("puts the owner's rating under the card instead of the date when the server says so", () => {
        const shown = cards(
            panel({ filters: filters({ sort: 'my_rating' }), entries: [entry({ rating: 8 })], ownerRatingsShown: true, isOwner: false }),
        );
        expect(shown).toContain('Alex rated it 8');
        expect(shown).not.toContain('2026');

        const dated = cards(panel({ filters: filters(), entries: [entry({ rating: 8 })], isOwner: false }));
        expect(dated).not.toContain('rated it');
        expect(dated).toContain('2026');
    });

    it('says so when the owner has not rated the title', () => {
        const caption = cards(panel({ filters: filters({ sort: 'owner_rating' }), entries: [entry()], ownerRatingsShown: true, isOwner: false }));

        expect(caption).toContain('–');
        expect(caption).toContain('Alex has not rated it');
    });

    it('hands the entry up when its owner removes it', async () => {
        const removable = entry({ id: 7 });
        const wrapper = panel({ filters: filters(), entries: [removable], removeLabel: 'Remove this play' });

        await wrapper.find('button[aria-label="Remove this play"]').trigger('click');

        expect(wrapper.emitted('remove')).toEqual([[removable]]);
    });
});
