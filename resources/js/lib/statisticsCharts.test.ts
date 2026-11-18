import {
    calendarWeeks,
    DAY_THRESHOLDS,
    fittingTicks,
    fromWeekday,
    MAX_RELEASE_COLUMNS,
    middleOut,
    monthName,
    monthOfYear,
    monthsOfDays,
    peakIndex,
    ratingColor,
    ratingRows,
    ratingShare,
    releaseColumns,
    rowSpread,
    scaleColor,
    scaleLegend,
    scaleStep,
    thinTicks,
    weekdayNames,
} from '@/lib/statisticsCharts';
import { setPageProps } from '@/test/page';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => setPageProps({}));

describe('the sequential scale', () => {
    it('puts a count on a fixed step, however busy the busiest day', () => {
        expect([0, 1, 2, 3, 4, 6, 7, 291].map((count) => scaleStep(count, DAY_THRESHOLDS))).toEqual([0, 1, 2, 2, 3, 3, 4, 4]);
    });

    it('colours nothing as the surface and the rest by step', () => {
        expect(scaleColor(0)).toBe('var(--color-surface-raised)');
        expect(scaleColor(4)).toBe('var(--color-scale-4)');
        expect(scaleColor(9)).toBe('var(--color-scale-4)');
    });

    it('names each step of its legend', () => {
        expect(scaleLegend(DAY_THRESHOLDS, (value) => `${value}+`).map((entry) => entry.label)).toEqual(['0', '1', '2–3', '4–6', '7+']);
    });
});

describe('calendarWeeks', () => {
    it('lays a period out in weeks from Monday, blank beyond its ends', () => {
        // 2025-01-01 was a Wednesday.
        const { weeks, months } = calendarWeeks('2025-01-01', '2025-01-12', { '2025-01-06': 3 }, 1);

        expect(weeks).toHaveLength(2);
        expect(weeks[0]?.map((cell) => cell.day)).toEqual([null, null, '2025-01-01', '2025-01-02', '2025-01-03', '2025-01-04', '2025-01-05']);
        expect(weeks[1]?.[0]).toEqual({ day: '2025-01-06', count: 3 });
        expect(months).toEqual([{ column: 0, month: 0 }]);
    });

    it("opens its weeks on the user's first weekday", () => {
        // Sunday first: Wednesday the 1st is the fourth day of the first week.
        expect(calendarWeeks('2025-01-01', '2025-01-04', {}, 7).weeks[0]?.map((cell) => cell.day)).toEqual([
            null,
            null,
            null,
            '2025-01-01',
            '2025-01-02',
            '2025-01-03',
            '2025-01-04',
        ]);
    });

    it('marks the column each month starts in', () => {
        expect(calendarWeeks('2025-01-27', '2025-02-09', {}, 1).months).toEqual([
            { column: 0, month: 0 },
            { column: 0, month: 1 },
        ]);
    });
});

describe('names and peaks', () => {
    it('names weekdays from Monday and months from January', () => {
        expect(weekdayNames('long')[0]).toBe('Monday');
        expect(weekdayNames('short')[6]).toBe('Sun');
        expect(weekdayNames('short', 7)[0]).toBe('Sun');
        expect(fromWeekday([1, 2, 3, 4, 5, 6, 7], 7)).toEqual([7, 1, 2, 3, 4, 5, 6]);
        expect(monthName(5)).toBe('Jun');
        expect(monthName(5, 'long')).toBe('June');
    });

    it('finds the first of the largest values, and none in an empty chart', () => {
        expect(peakIndex([1, 5, 5, 2])).toBe(1);
        expect(peakIndex([0, 0])).toBeNull();
    });
});

describe('the release-year chart', () => {
    const dot = (year: number, rating: number | null = null) => ({ year, rating });

    it('keeps every year between the oldest and the newest, best rated first', () => {
        const columns = releaseColumns([dot(2001, 5), dot(2003, 9), dot(2001, 8)], (entry) => entry.rating ?? 0);

        expect(columns.map((column) => column.year)).toEqual([2001, 2002, 2003]);
        expect(columns[0]?.dots.map((entry) => entry.rating)).toEqual([8, 5]);
    });

    it('folds the oldest years into one column when there are too many', () => {
        const columns = releaseColumns([dot(1930), dot(1950), dot(2025)], () => 0);

        expect(columns).toHaveLength(MAX_RELEASE_COLUMNS);
        expect(columns[0]).toEqual({ year: null, dots: [dot(1930), dot(1950)] });
        expect(columns[1]?.year).toBe(2025 - MAX_RELEASE_COLUMNS + 2);
    });

    it('lays a column out from the middle', () => {
        expect([0, 1, 2, 3, 4].map(middleOut)).toEqual([-0, 1, -1, 2, -2]);
    });

    it('colours ratings on the scale', () => {
        expect(ratingColor(null)).toBe('var(--color-chart-line)');
        expect(ratingColor(3)).toBe('var(--color-line-strong)');
        expect(ratingColor(6)).toBe('var(--color-scale-2)');
        expect(ratingColor(10)).toBe('var(--color-scale-4)');
    });
});

describe("the comparison with everyone else's ratings", () => {
    const points = [
        { type: 'movie', rating: 8, community: 7.2, titles: 2 },
        { type: 'tv', rating: 8, community: 8.6, titles: 1 },
        { type: 'movie', rating: 6, community: 6, titles: 3 },
        { type: 'game', rating: 5, community: 8.1, titles: 1 },
    ];

    it('places a rating along the scale, an average below its bottom at it', () => {
        expect(ratingShare(1)).toBe(0);
        expect(ratingShare(10)).toBe(1);
        expect(ratingShare(5.5)).toBe(0.5);
        expect(ratingShare(0.5)).toBe(0);
    });

    it("sums each rating up, highest first, everyone else's average weighted by titles", () => {
        const rows = ratingRows(points);

        expect(rows.map((row) => [row.rating, row.titles])).toEqual([
            [8, 3],
            [6, 3],
            [5, 1],
        ]);
        expect(rows[0]?.community).toBeCloseTo(7.6667, 3);
    });

    it("spreads a row's dots evenly within half a step either way, the same on every draw", () => {
        const spread = Array.from({ length: 50 }, (_, index) => rowSpread(index));

        expect(spread.every((value) => value >= -0.5 && value < 0.5)).toBe(true);
        expect(rowSpread(0)).toBe(-0.5);
        expect(new Set(spread.map((value) => value.toFixed(6))).size).toBe(50);
        expect(rowSpread(7)).toBe(spread[7]);
    });
});

describe('thinTicks', () => {
    const hours = Array.from({ length: 24 }, (_, hour) => `${String(hour).padStart(2, '0')}:00`);
    const everyThird = hours.map((_, hour) => hour).filter((hour) => hour % 3 === 0);

    it('keeps every tick where the labels have room', () => {
        expect([...thinTicks(everyThird, 24, 1000, hours)]).toEqual(everyThird);
    });

    it('keeps every other tick, and so on, where they would touch', () => {
        expect([...thinTicks(everyThird, 24, 260, hours)]).toEqual([0, 6, 12, 18]);
    });

    it('keeps them all before the chart is measured', () => {
        expect([...thinTicks(everyThird, 24, 0, hours)]).toEqual(everyThird);
    });

    it('keeps a lone tick', () => {
        expect([...thinTicks([4], 24, 100, hours)]).toEqual([4]);
    });
});

describe('fittingTicks', () => {
    const years = ['1990', '2000', '2010', '2020', '2024'];

    it('drops the label that would run into the one to its right, keeping the last', () => {
        expect([...fittingTicks([0, 1, 2, 3, 4], 30, years)].sort()).toEqual([0, 2, 4]);
    });

    it('keeps them all where there is room or before the chart is measured', () => {
        expect([...fittingTicks([0, 1, 2, 3, 4], 60, years)].sort()).toEqual([0, 1, 2, 3, 4]);
        expect([...fittingTicks([0, 1, 2, 3, 4], 0, years)].sort()).toEqual([0, 1, 2, 3, 4]);
    });
});

describe('monthsOfDays', () => {
    it('adds the days up month by month, across a year end, quiet months included', () => {
        expect(monthsOfDays('2025-11-15', '2026-01-10', { '2025-11-14': 9, '2025-11-20': 2, '2025-11-21': 1, '2026-01-02': 3 })).toEqual([
            { month: '2025-11', completed: 3, activeDays: 2 },
            { month: '2025-12', completed: 0, activeDays: 0 },
            { month: '2026-01', completed: 3, activeDays: 1 },
        ]);
    });

    it('names a month of a year', () => {
        expect(monthOfYear('2019-03')).toBe('March 2019');
    });
});
