import MakersCard from '@/components/statistics/MakersCard.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { MakerTally, RatedTitle } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const title = (name: string, rating: number | null, communityRating: number | null, year: number | null = 2020): RatedTitle => ({
    media: mediaSummary({ type: 'show', ref: name, title: name, year, posterPath: year === null ? null : `/${name}.jpg`, communityRating }),
    rating,
});

const makers: MakerTally[] = [
    { creator: { name: 'Showcase', kind: 'network', ref: '49', provider: 'tmdb', logoPath: null }, titles: 3, best: [0] },
    { creator: { name: 'Harbourside', kind: 'network', ref: null, provider: null, logoPath: null }, titles: 1, best: [1, 2] },
];

const mountCard = () =>
    mount(MakersCard, {
        props: {
            makers,
            titles: [title('Harbour Lights', 9, 7.4), title('Quiet Coast', null, 8.25), title('Unseen Pilot', null, null, null)],
            kind: 'network',
            type: 'show',
            period: '2025',
            scope: 'shows',
        },
        global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
    });

describe('MakersCard', () => {
    it('speaks of the largest maker first, with its page and its best rated titles', () => {
        const wrapper = mountCard();
        const text = wrapper.text();

        expect(text).toContain('Best rated: Showcase');
        expect(text).toContain('Harbour Lights');
        expect(text).toContain('Your rating');
        expect(wrapper.find('a[href*="49"]').text()).toBe('Network page');
    });

    it('puts a clicked bubble in the panel, without a page link where the maker has none', async () => {
        const wrapper = mountCard();

        await wrapper.findAll('button[aria-pressed]')[1]!.trigger('click');

        expect(wrapper.text()).toContain('Best rated: Harbourside');
        expect(wrapper.text()).toContain('Quiet Coast');
        expect(wrapper.text()).toContain('Everyone else');
        expect(wrapper.text()).toContain('Unseen Pilot');
        expect(wrapper.findAll('ol img')).toHaveLength(1);
        expect(wrapper.text()).not.toContain('Network page');
        expect(wrapper.findAll('button[aria-pressed="true"]')).toHaveLength(1);
    });
});
