import StatTile from '@/components/dashboard/StatTile.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';
import Statistics from '@/pages/Statistics.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { StatisticsPage, TypeTally } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let visits: [string, Record<string, unknown>][] = [];

beforeEach(() => {
    useMediaTypes();
    visits = [];
    vi.spyOn(router, 'get').mockImplementation(((url: string, params: Record<string, unknown>) => visits.push([url, params])) as never);
    setPageProps({});
});

const tally = (byType: [string, number][]): TypeTally => ({
    total: byType.reduce((sum, [, count]) => sum + count, 0),
    byType: byType.map(([type, count]) => ({ type, count })),
});

const zero = { amount: 0, watched: 0, plays: 0, titles: 0, estimatedMinutes: null };

function statisticsPage(overrides: Partial<StatisticsPage> = {}): StatisticsPage {
    return {
        period: { kind: 'year', year: 2025, from: '2025-01-01', to: '2025-12-31', days: 365 },
        type: null,
        years: [2024, 2025],
        types: [
            { type: 'movie', count: 2 },
            { type: 'show', count: 10 },
        ],
        earliest: '2024-02-01',
        summary: {
            // 250 + 420 minutes watched, 1355 pages read as about 1355 minutes.
            time: tally([
                ['show', 420],
                ['movie', 250],
                ['book', 1355],
            ]),
            readingMinutes: 1355,
            activeDays: 7,
            ratings: 3,
            averageRating: 7.33,
            ratingCounts: [0, 0, 0, 0, 0, 0, 2, 1, 0, 0],
            communityDelta: null,
        },
        tiles: {
            movie: { amount: 250, watched: 2, plays: 2, titles: 2, estimatedMinutes: null },
            show: { amount: 420, watched: 10, plays: 10, titles: 1, estimatedMinutes: null },
            game: zero,
            book: { amount: 1355, watched: 8, plays: 8, titles: 8, estimatedMinutes: 1355 },
        },
        titles: {
            titles: tally([
                ['movie', 2],
                ['show', 1],
            ]),
            completed: tally([['movie', 2]]),
            inProgress: tally([['show', 1]]),
            onHold: tally([]),
            dropped: tally([]),
        },
        first: { media: mediaSummary({ type: 'movie', title: 'Alpha', year: 1999 }), at: '2025-01-06T21:14:00+01:00' },
        last: { media: mediaSummary({ type: 'movie', title: 'Omega', year: 2024 }), at: '2025-12-31T23:02:00+01:00' },
        ...overrides,
    };
}

function page(props: StatisticsPage) {
    return mount(Statistics, {
        props: { page: props },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                Head: true,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
        },
    });
}

describe('Statistics', () => {
    it('sums up the period and what its titles became', () => {
        const text = page(statisticsPage()).text();

        expect(text).toContain('2025');
        expect(text).toContain('January 1 – December 31');
        // Marks count titles, a show once: two movies completed, the show still going.
        expect(text).toMatch(/marked\s*3\s*2\s*completed\s*1\s*in progress/);
        expect(text).toContain('of 365 days (2%)');
        expect(text).toContain('about every 52 days');
        expect(text).toMatch(/7\.33\s*average/);
        expect(text).toContain('most common: 7');
        expect(text).toContain('Alpha');
        expect(text).toContain('359 days');
    });

    it('reads the ratings chart out to screen readers', () => {
        const items = page(statisticsPage())
            .findAll('ul.sr-only li')
            .map((item) => item.text());

        expect(items).toHaveLength(RATING_MAX - RATING_MIN + 1);
        expect(items[7 - RATING_MIN]).toBe('Rated 7: 2');
    });

    it('leads with the time the period took, an estimate once reading is in it', () => {
        const wrapper = page(statisticsPage());
        const text = wrapper.text();

        // 2025 minutes: one day, nine hours and 45 minutes.
        expect(text).toMatch(/Time spent\s*≈\s*1\s*day\s*9\s*hours\s*45\s*minutes/);
        expect(text).toContain('reading estimated at your pace');
        // Each type's share of the time, the most first: the book's estimate is the largest.
        expect(text).toMatch(/Books\s*≈ 22h 35min\s*66\.9%/);
        expect(text).toMatch(/Shows\s*7h 0min\s*20\.7%/);

        // The book tile leads with the estimate and names what was read beneath it.
        const book = wrapper.findAllComponents(StatTile).find((tile) => tile.props('kind') === 'book');
        expect(book?.text()).toMatch(/≈\s*22\s*hours\s*35\s*minutes/);
        expect(book?.text()).toMatch(/8 books.*1,355 pages/);
    });

    it('picks a year, the whole history or the next year from the strip, and names a range it cannot pick', async () => {
        const wrapper = page(statisticsPage());
        const select = wrapper.find('select[aria-label="Period"]');

        expect(select.findAll('option').map((option) => option.text())).toEqual(['2025', '2024']);
        expect(wrapper.find('button[aria-label="Next year"]').attributes('disabled')).toBeDefined();

        await wrapper.find('button[aria-label="Previous year"]').trigger('click');
        expect(visits.at(-1)?.[1]).toEqual({ period: '2024' });

        const ranged = page(statisticsPage({ period: { kind: 'range', year: null, from: '2024-03-12', to: '2025-01-05', days: 300 } }));
        const options = ranged.find('select[aria-label="Period"]').findAll('option');

        expect(options.at(-1)?.text()).toBe('Mar 12, 2024\u2009–\u2009Jan 5, 2025');
        expect(options.at(-1)?.attributes('disabled')).toBeDefined();
        expect(ranged.find('button[aria-label="Previous year"]').attributes('disabled')).toBeDefined();
    });

    it('changes the type and keeps the period', async () => {
        const wrapper = page(statisticsPage({ period: { kind: 'range', year: null, from: '2024-03-12', to: '2025-01-05', days: 300 } }));

        wrapper.findComponent(TypeFilter).vm.$emit('update:modelValue', 'movie');

        expect(visits.at(-1)?.[1]).toEqual({ from: '2024-03-12', to: '2025-01-05', type: 'movie' });
    });

    it("keeps one type's view simple: its totals beside the time, no tiles, the sums along the bottom", () => {
        const wrapper = page(
            statisticsPage({
                type: 'movie',
                summary: {
                    time: tally([['movie', 250]]),
                    readingMinutes: 0,
                    activeDays: 2,
                    ratings: 2,
                    averageRating: 6.09,
                    ratingCounts: [0, 0, 0, 0, 0, 1, 1, 0, 0, 0],
                    communityDelta: -0.76,
                },
            }),
        );
        const text = wrapper.text();

        expect(wrapper.findAllComponents(StatTile)).toHaveLength(0);
        expect(text).toMatch(/Time spent\s*4\s*hours\s*10\s*minutes\s*2 movies/);
        expect(text).toContain('0.76 below others');
    });

    it('says so when the period holds nothing', () => {
        const text = page(
            statisticsPage({
                summary: {
                    time: tally([]),
                    readingMinutes: 0,
                    activeDays: 0,
                    ratings: 0,
                    averageRating: null,
                    ratingCounts: Array.from({ length: 10 }, () => 0),
                    communityDelta: null,
                },
                titles: {
                    titles: tally([]),
                    completed: tally([]),
                    inProgress: tally([]),
                    onHold: tally([]),
                    dropped: tally([]),
                },
                first: null,
                last: null,
            }),
        ).text();

        expect(text).toContain('Nothing was completed in this period.');
        expect(text).toContain('none rated');
    });
});
