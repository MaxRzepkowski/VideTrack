import CreatorsSection from '@/components/statistics/CreatorsSection.vue';
import { useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { PeriodSpan, StatisticsCreators } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const year: PeriodSpan = { kind: 'year', year: 2025, from: '2025-01-01', to: '2025-12-31', days: 365 };

const creators: StatisticsCreators = {
    crafts: [
        {
            department: 'acting',
            types: ['show', 'movie'],
            people: [
                { name: 'Alex Morgan', provider: 'tmdb', ref: '7', profilePath: '/alex.jpg', titles: 7 },
                { name: 'Sam Rivera', provider: 'tmdb', ref: '8', profilePath: null, titles: 1 },
            ],
        },
        { department: 'author', types: ['book'], people: [{ name: 'Joanne Rowling', provider: null, ref: null, profilePath: null, titles: 2 }] },
    ],
};

const mountSection = (props: { creators: StatisticsCreators; type?: string | null }) =>
    mount(CreatorsSection, {
        props: { period: year, type: null, ...props },
        global: { stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } } },
    });

describe('CreatorsSection', () => {
    it('shows a card per craft, a portrait or initials for each person, and links where there is a page', () => {
        const wrapper = mountSection({ creators });
        const text = wrapper.text();

        expect(text).toContain('Cast');
        expect(text).toContain('Authors');
        expect(text).toContain('Alex Morgan');
        expect(text).toContain('7 titles');
        expect(text).toContain('SR');
        expect(text).toContain('JR');
        expect(wrapper.findAll('img')).toHaveLength(1);
        expect(wrapper.findAll('a')).toHaveLength(2);
    });

    it('counts in the type when one is chosen, and is gone when nobody is credited', () => {
        expect(mountSection({ creators, type: 'movie' }).text()).toContain('7 movies');
        expect(mountSection({ creators: { crafts: [] } }).html()).not.toContain('section');
    });
});
