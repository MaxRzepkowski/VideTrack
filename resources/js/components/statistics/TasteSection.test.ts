import GenresCard from '@/components/statistics/GenresCard.vue';
import LengthCard from '@/components/statistics/LengthCard.vue';
import ReleaseChart from '@/components/statistics/ReleaseChart.vue';
import TasteSection from '@/components/statistics/TasteSection.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { GenreStat, PeriodSpan, StatisticsOrigins, StatisticsTaste } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const year: PeriodSpan = { kind: 'year', year: 2025, from: '2025-01-01', to: '2025-12-31', days: 365 };

const genre = (name: string, titles: number, amount: number, averageRating: number | null, rated: number): GenreStat => ({
    name,
    titles,
    amount,
    averageRating,
    rated,
});

const taste = (overrides: Partial<StatisticsTaste> = {}): StatisticsTaste => ({
    readIn: null,
    readingEstimated: false,
    top: [
        { media: mediaSummary({ type: 'show', title: 'Long Show', year: 2011 }), amount: 2280, estimatedMinutes: null, parts: 96 },
        { media: mediaSummary({ type: 'movie', title: 'Short Film', year: 2023 }), amount: 164, estimatedMinutes: null, parts: null },
    ],
    releases: [
        { year: 2011, type: 'show', rating: 8 },
        { year: 2023, type: 'movie', rating: null },
    ],
    genres: [
        genre('Drama', 12, 600, 6.5, 2),
        ...Array.from({ length: 11 }, (_, index) => genre(`Genre ${index}`, 11 - index, index * 10, 7 + index / 10, 4)),
    ],
    lengths: [
        {
            type: 'movie',
            titles: 34,
            total: 3912,
            average: 115,
            longest: mediaSummary({ type: 'movie', title: 'Longest', year: 2023 }),
            longestRuntime: 164,
        },
    ],
    ...overrides,
});

const noOrigins: StatisticsOrigins = { countries: [], countryTypes: [], languages: [], languageTypes: [], makers: [], makerKind: null, titles: [] };

const mountSection = (props: { taste: StatisticsTaste; origins?: StatisticsOrigins; type?: string | null }) =>
    mount(TasteSection, {
        props: { period: year, type: null, origins: noOrigins, ...props },
        global: {
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' }, WorldMap: true },
        },
    });

describe('TasteSection', () => {
    it('shows where the time went, the release years, the genres and the film length', () => {
        const wrapper = mountSection({ taste: taste() });
        const text = wrapper.text();

        expect(text).toContain('Titles that took the most time');
        expect(text).toContain('Long Show');
        expect(text).toContain('96 episodes');
        expect(text).toContain('Which years the titles came out in');
        expect(text).toContain('Movie length');
        expect(text).toContain('Longest movie');
        expect(wrapper.findComponent(ReleaseChart).props('type')).toBeNull();
        expect(wrapper.findComponent(LengthCard).exists()).toBe(true);
    });

    it('ranks the genres by the measure picked, ten at first', async () => {
        const card = mountSection({ taste: taste() }).findComponent(GenresCard);

        expect(card.text()).toContain('Genres by number of titles');
        expect(card.findAll('li')).toHaveLength(10);

        await card.find('select').setValue('rating');

        expect(card.text()).toContain('Genres by average rating');
        // Drama rests on two ratings: too few to rank by its average.
        expect(card.text()).not.toContain('Drama');
        expect(card.findAll('li')[0]?.text()).toContain('Genre 10');
    });

    it('marks reading counted as time as an estimate, and says where the pace comes from', () => {
        const text = mountSection({
            taste: taste({
                readingEstimated: true,
                top: [{ media: mediaSummary({ type: 'book', title: 'Thick Book', year: 2020 }), amount: 300, estimatedMinutes: 300, parts: null }],
            }),
        }).text();

        expect(text).toContain('≈ 5h');
        expect(text).toContain('Reading time is an estimate at your reading pace, set in Preferences.');
    });

    it('puts the estimate beside what was read when the type is read', () => {
        const text = mountSection({
            type: 'book',
            taste: taste({
                readIn: 'pages',
                top: [{ media: mediaSummary({ type: 'book', title: 'Thick Book', year: 2020 }), amount: 300, estimatedMinutes: 300, parts: null }],
            }),
        }).text();

        expect(text).toMatch(/300\s*pages/);
        expect(text).toContain('≈ 5h');
    });

    it('says where the titles come from and which networks they ran on, and hides both without such data', () => {
        const origins: StatisticsOrigins = {
            countries: [
                { code: 'JP', titles: 3 },
                { code: 'US', titles: 1 },
            ],
            countryTypes: ['show', 'movie'],
            languages: [{ code: 'ja', titles: 3 }],
            languageTypes: ['show'],
            makers: [{ creator: { name: 'Showcase', kind: 'network', ref: '49', provider: 'tmdb', logoPath: null }, titles: 2, best: [0] }],
            makerKind: 'network',
            titles: [{ media: mediaSummary({ type: 'show', title: 'Harbour Lights', year: 2019, communityRating: 8.25 }), rating: null }],
        };
        const text = mountSection({ taste: taste(), origins }).text();

        expect(text).toContain('Where titles come from');
        expect(text).toContain('Japan');
        expect(text).toContain('Japanese');
        expect(text).toContain('3 titles');
        expect(text).toContain('Which networks and services titles ran on');
        expect(text).toContain('Showcase');
        expect(text).toContain('Best rated: Showcase');
        expect(text).toContain('Harbour Lights');
        expect(text).toContain('8.3');

        const films = mountSection({
            taste: taste(),
            type: 'movie',
            origins: { ...origins, countries: [], countryTypes: [], languages: [{ code: 'en', titles: 2 }], languageTypes: ['movie'] },
        }).text();

        expect(films).toContain('Where movies come from');
        expect(films).toContain('English');
        expect(films).toContain('2 movies');
        expect(films).not.toContain('Titles from a country');

        const bare = mountSection({ taste: taste() }).text();

        expect(bare).not.toContain('Where titles come from');
        expect(bare).not.toContain('Which networks');
    });
});
