import { averages, bucketName, bucketTick, tickEvery, timeSpent } from '@/lib/statisticsActivity';
import { useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { StatisticsActivity } from '@/types/generated';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const activity = (overrides: Partial<StatisticsActivity> = {}): StatisticsActivity => ({
    bucket: 'month',
    buckets: [],
    series: [],
    completions: 365,
    watchMinutes: 365 * 60,
    readingMinutes: 0,
    days: null,
    months: null,
    weekdays: [0, 0, 0, 0, 0, 0, 0],
    hours: Array.from({ length: 24 }, () => 0),
    busiestDay: null,
    firstWeekday: 1,
    ...overrides,
});

describe('buckets', () => {
    it('names a month short under the axis and in full elsewhere; a year as itself', () => {
        expect(bucketTick('2025-06', 'month')).toBe('Jun');
        expect(bucketName('2025-06', 'month')).toBe('June 2025');
        expect(bucketTick('2019', 'year')).toBe('2019');
        expect(bucketName('2019', 'year')).toBe('2019');
    });

    it('names a day by its date, a week by the days it spans', () => {
        expect(bucketTick('2025-11-03', 'day')).toBe('Nov 3');
        expect(bucketName('2025-11-03', 'day')).toBe('Monday, November 3');
        expect(bucketTick('2025-11-03', 'week')).toBe('Nov 3');
        // The locale writes the range, thin spaces around the dash included.
        expect(bucketName('2025-11-03', 'week')).toMatch(/^November 3\s–\s9$/u);
        expect(bucketName('2025-12-29', 'week')).toMatch(/^December 29, 2025\s–\sJanuary 4, 2026$/u);
    });

    it('prints only as many ticks as there is room for', () => {
        const every = tickEvery(12, 4);

        expect([0, 1, 2, 3, 6, 9].map(every)).toEqual([true, false, false, true, true, true]);
        expect(tickEvery(5, 12)(3)).toBe(true);
    });
});

describe('time spent', () => {
    it('says what filled the time', () => {
        expect(timeSpent(125, 0, ['movie'])).toBe('2h 5min watching');
        expect(timeSpent(125, 0, ['game'])).toBe('2h 5min playing');
        expect(timeSpent(125, 0, ['show', 'game'])).toBe('2h 5min watching and playing');
    });

    it('adds reading as an estimate, and says it is one', () => {
        expect(timeSpent(125, 60, ['movie', 'book'])).toBe('≈ 3h 5min, reading estimated');
        expect(timeSpent(0, 90, ['book'])).toBe('≈ 1h 30min, reading estimated');
    });

    it('has nothing to say about no time at all', () => {
        expect(timeSpent(0, 0, ['book'])).toBeNull();
        expect(timeSpent(0, 0, ['movie'])).toBeNull();
    });
});

describe('averages', () => {
    it('spreads a period over its months, weeks and days', () => {
        const paces = averages(activity(), 365, ['movie']);

        expect(paces.map((pace) => pace.value)).toEqual(['30.4 completed', '7 completed', '1 completed']);
        expect(paces[2]?.time).toBe('1h 0min watching');
    });

    it('has none for a period of no days', () => {
        expect(averages(activity(), 0, ['movie'])).toEqual([]);
    });
});
