import CreatorCard from '@/components/media/CreatorCard.vue';
import { useMediaModals } from '@/composables/useMediaModals';
import { vTooltip } from '@/directives/tooltip';
import { mediaSummary } from '@/test/factories';
import { nth } from '@/test/lists';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

const global = { directives: { tooltip: vTooltip }, stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } };

afterEach(() => useMediaModals().close());

describe('CreatorCard', () => {
    it('shows a person with what they are known for, linking to their page', () => {
        const wrapper = mount(CreatorCard, {
            props: { media: mediaSummary({ type: 'person', ref: '6384', title: 'Keanu Reeves', extra: { knownFor: ['The Matrix', 'John Wick'] } }) },
            global,
        });

        expect(wrapper.text()).toContain('The Matrix, John Wick');
        expect(wrapper.find('a').attributes('href')).toBe('/person.details/tmdb/6384');
    });

    it('badges a network, and lights its lists and bell from the page', () => {
        setPageProps({ creatorStates: { 'network:tmdb:49': { followed: true, listIds: [3] } } });

        const wrapper = mount(CreatorCard, {
            props: { media: mediaSummary({ type: 'company', ref: '49', title: 'HBO', extra: { kind: 'network' } }) },
            global,
        });
        const buttons = wrapper.findAll('button');
        const lists = nth(buttons, 0);
        const bell = nth(buttons, 1);

        expect(wrapper.text()).toContain('Network');
        expect(lists.classes()).toContain('bg-action-watchlist');
        expect(bell.attributes('aria-pressed')).toBe('true');
    });

    it('opens the lists of the creator it stands for', async () => {
        const wrapper = mount(CreatorCard, { props: { media: mediaSummary({ type: 'company', ref: '174', title: 'Warner Bros.' }) }, global });

        await nth(wrapper.findAll('button'), 0).trigger('click');

        expect(useMediaModals().modals.creatorLists).toEqual({ creator: { kind: 'company', provider: 'tmdb', ref: '174' }, name: 'Warner Bros.' });
        expect(wrapper.text()).toContain('Studio');
    });

    it('badges a company by what the server says it makes, not by who serves it', () => {
        const wrapper = mount(CreatorCard, {
            props: { media: mediaSummary({ type: 'company', provider: 'igdb', ref: '70', title: 'Nintendo', extra: { makes: 'game' } }) },
            global,
        });

        expect(wrapper.text()).toContain('Developer / publisher');
        expect(wrapper.find('.bg-media-game').exists()).toBe(true);

        const studio = mount(CreatorCard, {
            props: { media: mediaSummary({ type: 'company', ref: '174', title: 'Warner Bros.', extra: { makes: 'movie' } }) },
            global,
        });

        expect(studio.text()).toContain('Studio');
        expect(studio.find('.bg-media-movie').exists()).toBe(true);
    });
});
