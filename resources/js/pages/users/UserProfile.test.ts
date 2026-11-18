import SectionMoreLink from '@/components/SectionMoreLink.vue';
import UserProfile from '@/pages/users/UserProfile.vue';
import { useMediaTypes } from '@/test/factories';
import { route } from '@/test/route';
import { shallowMount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useReloadAfterWrites', () => ({ useReloadAfterWrites: vi.fn() }));

function page(isOwner: boolean) {
    return shallowMount(UserProfile, {
        props: {
            profile: { id: 1, name: 'Alex', username: 'alex', bio: null, avatar: null, isPrivate: false, memberSince: '2026-01-01' },
            isOwner,
            favoritesCount: 0,
            favorites: [],
            stats: {},
        },
        global: { renderStubDefaultSlot: true, stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

const statisticsLinks = (wrapper: ReturnType<typeof page>) =>
    wrapper.findAllComponents(SectionMoreLink).filter((link) => link.props('href') === route('statistics'));

beforeEach(() => {
    useMediaTypes();
});

describe('UserProfile', () => {
    it('leads its owner on to their statistics, in the heading and under the tiles', () => {
        expect(statisticsLinks(page(true)).map((link) => link.props('placement'))).toEqual(['heading', 'below']);
    });

    it('does not offer a visitor statistics of a library that is not theirs', () => {
        expect(statisticsLinks(page(false))).toHaveLength(0);
    });
});
