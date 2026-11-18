import {
    activeEvery,
    commonestRating,
    communityComparison,
    countTitles,
    currentQuery,
    daysBetween,
    formatPercent,
    formatRating,
    lowerList,
    partRange,
    periodDates,
    periodTitle,
    rangeLabel,
    rangeShortcuts,
    rankGenres,
    scopeName,
    statisticsParams,
    timeRows,
    titleMarks,
    typeAndYear,
    typeNoun,
    weekdayDate,
    yearWindow,
} from '@/lib/statistics';
import { useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { PeriodSpan, YearCount } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const span = (overrides: Partial<PeriodSpan> = {}): PeriodSpan => ({
    kind: 'year',
    year: 2025,
    from: '2025-01-01',
    to: '2025-12-31',
    days: 365,
    ...overrides,
});

const years = (from: number, to: number): YearCount[] =>
    Array.from({ length: to - from + 1 }, (_, index) => ({ year: from + index, completions: 10 }));

describe('statisticsParams', () => {
    it('writes a range of days instead of a period', () => {
        expect(statisticsParams({ period: 2025, from: '2024-03-12', to: '2025-01-05', type: 'movie' })).toEqual({
            from: '2024-03-12',
            to: '2025-01-05',
            type: 'movie',
        });
    });

    it('leaves out what is not set', () => {
        expect(statisticsParams({ period: 'all', type: null })).toEqual({ period: 'all' });
        expect(statisticsParams({})).toEqual({});
    });

    it('keeps the period when only the type changes', () => {
        expect(currentQuery(span({ kind: 'range', year: null, from: '2024-03-12', to: '2025-01-05' }), 'show')).toEqual({
            from: '2024-03-12',
            to: '2025-01-05',
            type: 'show',
        });
    });
});

describe('period labels', () => {
    it('names a year by its number and its dates without the year', () => {
        expect(periodTitle(span())).toBe('2025');
        expect(periodDates(span())).toBe('January 1 – December 31');
    });

    it('names the whole history, its dates with years', () => {
        const whole = span({ kind: 'all', year: null, from: '1999-04-05', to: '2026-10-10', days: 10050 });

        expect(periodTitle(whole)).toBe('All time');
        expect(periodDates(whole)).toContain('April 5, 1999');
    });

    it('names a range by its two days', () => {
        const range = span({ kind: 'range', year: null, from: '2024-03-12', to: '2025-01-05', days: 300 });

        // The locale writes the range, thin spaces around the dash included.
        expect(periodTitle(range)).toMatch(/^Mar 12, 2024\s–\sJan 5, 2025$/u);
        expect(periodDates(range)).toBeNull();
    });

    it('says nothing about days a history with nothing finished does not have', () => {
        expect(periodDates(span({ kind: 'all', year: null, from: null, days: 0 }))).toBeNull();
    });
});

describe('yearWindow', () => {
    it('shows every year when they fit', () => {
        expect(yearWindow(years(2020, 2023), 2022, 8)).toEqual({ shown: years(2020, 2023), before: false, after: false });
    });

    it('ends at the newest years by default', () => {
        const visible = yearWindow(years(2010, 2026), 2025, 8);

        expect(visible.shown.map((entry) => entry.year)).toEqual([2019, 2020, 2021, 2022, 2023, 2024, 2025, 2026]);
        expect(visible.before).toBe(true);
        expect(visible.after).toBe(false);
    });

    it('keeps an older chosen year in view, its next year beside it', () => {
        const visible = yearWindow(years(2000, 2026), 2005, 4);

        expect(visible.shown.map((entry) => entry.year)).toEqual([2003, 2004, 2005, 2006]);
        expect(visible.after).toBe(true);
    });

    it('pages back by whole windows and stops at the first year', () => {
        expect(yearWindow(years(2010, 2026), null, 8, 1).shown.map((entry) => entry.year)).toEqual([2011, 2012, 2013, 2014, 2015, 2016, 2017, 2018]);
        expect(yearWindow(years(2010, 2026), null, 8, 5).shown[0]?.year).toBe(2010);
    });
});

describe('rangeShortcuts', () => {
    it('ends the rolling ranges today and takes the whole previous month', () => {
        const shortcuts = Object.fromEntries(rangeShortcuts('2026-03-15').map((shortcut) => [shortcut.key, [shortcut.from, shortcut.to]]));

        expect(shortcuts.last_30_days).toEqual(['2026-02-14', '2026-03-15']);
        expect(shortcuts.last_12_months).toEqual(['2025-03-16', '2026-03-15']);
        expect(shortcuts.year_to_date).toEqual(['2026-01-01', '2026-03-15']);
        expect(shortcuts.previous_month).toEqual(['2026-02-01', '2026-02-28']);
    });

    it('counts days between calendar dates', () => {
        expect(daysBetween('2025-01-06', '2025-12-31')).toBe(359);
    });
});

describe('numbers and words', () => {
    it('names the titles of a view across every type or by the one type', () => {
        expect(countTitles(null, 3)).toBe('3 titles');
        expect(countTitles('movie', 1)).toBe('1 movie');
        expect(typeNoun(null)).toBe('titles');
        expect(typeNoun('movie')).toBe('movies');
        expect(scopeName(null)).toBe(trans('statistics.all_types_together'));
        expect(scopeName('movie')).toBe('movies');
    });

    it("writes shares, ratings and lists the locale's way", () => {
        expect(formatPercent(202, 232)).toBe('87%');
        expect(formatPercent(1, 0)).toBe('0%');
        expect(formatRating(6.5)).toBe('6.50');
        expect(lowerList(['Episodes', 'Movies', 'Games'])).toBe('episodes, movies, and games');
    });

    it('says the weekday of a moment, with the year only when asked', () => {
        expect(weekdayDate('2025-01-06T12:00:00+00:00', false)).toBe('Monday, January 6');
        expect(weekdayDate('2025-01-06T12:00:00+00:00', true)).toBe('Monday, January 6, 2025');
    });

    it('splits the titles by mark, in a fixed order with every mark present', () => {
        const tally = (total: number) => ({ total, byType: [] });
        const marks = titleMarks({
            titles: tally(10),
            completed: tally(5),
            inProgress: tally(3),
            onHold: tally(0),
            dropped: tally(2),
        });

        expect(marks.map((entry) => [entry.mark, entry.counted])).toEqual([
            ['completed', '5 completed'],
            ['inProgress', '3 in progress'],
            ['onHold', '0 on hold'],
            ['dropped', '2 dropped'],
        ]);
    });

    it('names a title by its type and year, the type alone without a year', () => {
        expect(typeAndYear('movie', 1983)).toBe('Movie, 1983');
        expect(typeAndYear('movie', null)).toBe('Movie');
    });

    it('spells two days out with long months when asked', () => {
        expect(rangeLabel('2025-05-25', '2025-06-29')).toBe('May 25\u2009–\u2009Jun 29, 2025');
        expect(rangeLabel('2025-05-25', '2025-06-29', 'long')).toBe('May 25\u2009–\u2009June 29, 2025');
    });

    it('names a run of parts by episode codes, by bare numbers without a season', () => {
        expect(partRange({ season: 2, number: 11 }, { season: 4, number: 3 })).toBe('S02E11–S04E03');
        expect(partRange({ season: null, number: 1 }, { season: null, number: 13 })).toBe('1–13');
        expect(partRange({ season: 1, number: null }, { season: 1, number: 2 })).toBeNull();
    });

    it("compares a rating with everyone else's, a rounding away from them being the same", () => {
        expect(communityComparison(-0.76)).toBe('0.76 below others');
        expect(communityComparison(1.2)).toBe('1.20 above others');
        expect(communityComparison(0.004)).toBe('same as others');
    });
});

describe('rankGenres', () => {
    const genres = [
        { name: 'A', titles: 5, amount: 10, averageRating: 9, rated: 1 },
        { name: 'B', titles: 3, amount: 50, averageRating: 6, rated: 3 },
    ];

    it('orders by the measure and leaves out an average resting on too few ratings', () => {
        expect(rankGenres(genres, 'titles').map((entry) => entry.genre.name)).toEqual(['A', 'B']);
        expect(rankGenres(genres, 'amount').map((entry) => entry.value)).toEqual([50, 10]);
        expect(rankGenres(genres, 'rating').map((entry) => entry.genre.name)).toEqual(['B']);
    });

    it('lists where the time went, the most first, a sliver kept for a type with next to none', () => {
        const rows = timeRows({
            total: 10_010,
            byType: [
                { type: 'movie', count: 2000 },
                { type: 'show', count: 8000 },
                { type: 'comic', count: 10 },
            ],
        });

        expect(rows.map((row) => [row.type, row.share])).toEqual([
            ['show', '79.9%'],
            ['movie', '20.0%'],
            ['comic', '<0.1%'],
        ]);
        expect(rows[0]?.width).toBe(100);
        expect(rows[1]?.width).toBe(25);
        expect(rows[2]?.width).toBe(0.6);
    });

    it('says how often an active day came round', () => {
        expect(activeEvery(3975, 1949)).toBe(2);
        expect(activeEvery(30, 30)).toBe(1);
        expect(activeEvery(30, 0)).toBeNull();
    });

    it('finds the rating given most often', () => {
        expect(commonestRating([18, 32, 70, 120, 230, 390, 430, 280, 110, 35], 1)).toBe(7);
        expect(commonestRating([0, 0, 0], 1)).toBeNull();
    });
});
