import ActivityPanel from '@/components/users/ActivityPanel.vue';
import UserActivity from '@/pages/users/UserActivity.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import type { ActivityEntry, ActivityFilters } from '@/types/media';
import { flushPromises, shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const actions = { deletePlay: vi.fn(), rate: vi.fn(), setFavorite: vi.fn() };
const answer = vi.fn<(request: { message: string; confirmLabel: string }) => Promise<boolean>>();

vi.mock('@/composables/useMediaActions', () => ({ useMediaActions: () => actions }));
vi.mock('@/composables/useReloadAfterWrites', () => ({ useReloadAfterWrites: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ confirm: (request: { message: string; confirmLabel: string }) => answer(request) }));

const filters: ActivityFilters = {
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
};

const entry: ActivityEntry = {
    id: 7,
    at: '2026-10-01T20:00:00Z',
    media: mediaSummary({ title: 'Heat' }),
    day: null,
    rating: null,
    outcome: null,
    completions: null,
};

function page(panel: 'history' | 'ratings' | 'favorites') {
    return shallowMount(UserActivity, {
        props: {
            panel,
            profile: { id: 1, name: 'Alex', username: 'alex', bio: null, avatar: null, isPrivate: false, memberSince: '2026-01-01' },
            isOwner: true,
            entries: [entry],
            pagination: null,
            filters,
            genreOptions: [],
            typeOptions: [],
            sortOptions: ['activity'],
            ownerRatingsShown: false,
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

async function removeFrom(panel: 'history' | 'ratings' | 'favorites'): Promise<ReturnType<typeof page>> {
    const wrapper = page(panel);

    wrapper.findComponent(ActivityPanel).vm.$emit('remove', entry);
    await flushPromises();

    return wrapper;
}

beforeEach(() => {
    useMediaTypes();
    vi.clearAllMocks();
    answer.mockResolvedValue(true);
});

describe('UserActivity', () => {
    it('removes a play from the history once the user agrees', async () => {
        await removeFrom('history');

        expect(answer.mock.lastCall?.[0].message).toBe('This play leaves your history and statistics. This cannot be undone.');
        expect(actions.deletePlay).toHaveBeenCalledWith(entry.media, 7);
    });

    it('clears a rating, and takes a heart back, each from its own panel', async () => {
        await removeFrom('ratings');
        expect(actions.rate).toHaveBeenCalledWith(entry.media, null);

        await removeFrom('favorites');
        expect(actions.setFavorite).toHaveBeenCalledWith(entry.media, false);
    });

    it('leaves everything as it was when the user backs out', async () => {
        answer.mockResolvedValue(false);

        await removeFrom('history');

        expect(actions.deletePlay).not.toHaveBeenCalled();
    });

    it('sets the panel up for what it lists', () => {
        const history = page('history').findComponent(ActivityPanel);
        const favorites = page('favorites').findComponent(ActivityPanel);

        expect(history.props('routeName')).toBe('users.history');
        expect(history.props('withStatus')).toBe(true);
        expect(favorites.props('sortLabel')).toBeNull();
        expect(favorites.props('removeLabel')).toBe('Remove from favorites');
    });
});
