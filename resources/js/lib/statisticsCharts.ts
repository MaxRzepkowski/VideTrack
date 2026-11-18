import { monthCells } from '@/lib/calendar';
import { pageLocale } from '@/lib/pageContext';
import { RATING_MAX, RATING_MIN } from '@/lib/ratings';

/**
 * The shapes the statistics charts draw: a step on the sequential scale, the
 * weeks of a calendar, the names of weekdays and months. Days are calendar
 * dates (YYYY-MM-DD) already on the user's clock, so they are read in UTC.
 */

/** The scale's steps beneath "nothing": `--color-scale-1` … `--color-scale-4`. */
export const SCALE_STEPS = 4;

/** `var(--color-…)` of a step on the scale; 0 is nothing at all. */
export function scaleColor(step: number): string {
    return step <= 0 ? 'var(--color-surface-raised)' : `var(--color-scale-${Math.min(step, SCALE_STEPS)})`;
}

/**
 * The step a count falls on, given where steps 1–4 begin (ascending): with
 * [1, 2, 4, 7], 0 → 0, 1 → 1, 2–3 → 2, 4–6 → 3, 7 or more → 4. A day of a
 * bulk entry (hundreds of episodes) lands on the top step like any busy day,
 * so it cannot wash out the rest.
 */
export function scaleStep(count: number, thresholds: readonly number[]): number {
    let step = 0;

    for (const threshold of thresholds) {
        if (count >= threshold) {
            step += 1;
        }
    }

    return step;
}

/** The legend of a scale: "0", "1", "2–3", "4–6", "7 or more". */
export function scaleLegend(thresholds: readonly number[], orMore: (value: number) => string): { step: number; label: string }[] {
    const legend = [{ step: 0, label: '0' }];

    thresholds.forEach((from, index) => {
        const next = thresholds[index + 1];
        const label = next === undefined ? orMore(from) : next - 1 === from ? String(from) : `${from}–${next - 1}`;
        legend.push({ step: index + 1, label });
    });

    return legend;
}

/** What was finished in a day: one, a few, an evening, a marathon. */
export const DAY_THRESHOLDS = [1, 2, 4, 7] as const;

/** What was finished in a month, on the whole history's grid of years and months. */
export const MONTH_THRESHOLDS = [1, 10, 30, 60] as const;

/** A day of a calendar grid; `day` is null where the grid runs past the period. */
export interface CalendarCell {
    day: string | null;
    count: number;
}

const WEEK = 7;

/**
 * The days from `from` to `to` as columns of weeks, opening on the user's
 * first weekday (ISO: 1 is Monday), the way a wall calendar lays them out,
 * with the column each month starts in.
 */
export function calendarWeeks(
    from: string,
    to: string,
    counts: Record<string, number>,
    firstWeekday: number,
): { weeks: CalendarCell[][]; months: { column: number; month: number }[] } {
    const cells = monthCells(from, to, firstWeekday);
    const weeks: CalendarCell[][] = [];
    const months: { column: number; month: number }[] = [];

    cells.forEach((cell, index) => {
        const column = Math.floor(index / WEEK);

        if (!cell.filler && (cell.date.endsWith('-01') || cell.date === from)) {
            months.push({ column, month: Number(cell.date.slice(5, 7)) - 1 });
        }

        (weeks[column] ??= []).push({ day: cell.filler ? null : cell.date, count: cell.filler ? 0 : (counts[cell.date] ?? 0) });
    });

    return { weeks, months };
}

/** Values kept Monday first, turned to open on the user's first weekday (ISO: 1 is Monday). */
export function fromWeekday<T>(mondayFirst: readonly T[], firstWeekday: number): T[] {
    const shift = (firstWeekday - 1) % WEEK;

    return [...mondayFirst.slice(shift), ...mondayFirst.slice(0, shift)];
}

/** Weekday names, opening on the given ISO weekday (Monday by default): "Monday" or "Mon". */
export function weekdayNames(style: 'long' | 'short', firstWeekday = 1): string[] {
    // 2024-01-01 was a Monday.
    const mondayFirst = Array.from({ length: WEEK }, (_, index) =>
        new Intl.DateTimeFormat(pageLocale(), { weekday: style, timeZone: 'UTC' }).format(Date.UTC(2024, 0, 1 + index)),
    );

    return fromWeekday(mondayFirst, firstWeekday);
}

/** A month's short name, January being 0: "Jan". */
export function monthName(month: number, style: 'short' | 'long' = 'short'): string {
    return new Intl.DateTimeFormat(pageLocale(), { month: style, timeZone: 'UTC' }).format(Date.UTC(2024, month, 1));
}

/** The index of the largest value, the first one on a tie; null when all are zero. */
export function peakIndex(values: readonly number[]): number | null {
    let peak: number | null = null;

    values.forEach((value, index) => {
        if (value > 0 && (peak === null || value > (values[peak] ?? 0))) {
            peak = index;
        }
    });

    return peak;
}

/** The highest rating still drawn barely there; each rating above it climbs a step of the scale. */
const FAINT_RATING_MAX = 4;

/** The lowest rating drawn at the scale's brightest step. */
const BRIGHTEST_RATING_MIN = FAINT_RATING_MAX + SCALE_STEPS;

/** The colour of a rating on the scale: the top ratings brightest, the lowest barely there, none a neutral grey. */
export function ratingColor(rating: number | null): string {
    if (rating === null) {
        return 'var(--color-chart-line)';
    }

    if (rating <= FAINT_RATING_MAX) {
        return 'var(--color-line-strong)';
    }

    return scaleColor(Math.min(SCALE_STEPS, rating - FAINT_RATING_MAX));
}

/** The legend of ratingColor: the barely-there band, each step, the brightest band; then "not rated" beside it. */
export const RATING_LEGEND: { label: string; rating: number }[] = [
    { label: `${RATING_MIN}–${FAINT_RATING_MAX}`, rating: FAINT_RATING_MAX },
    ...Array.from({ length: SCALE_STEPS - 1 }, (_, step) => ({ label: String(FAINT_RATING_MAX + step + 1), rating: FAINT_RATING_MAX + step + 1 })),
    { label: `${BRIGHTEST_RATING_MIN}–${RATING_MAX}`, rating: BRIGHTEST_RATING_MIN },
];

/** Columns beyond which the oldest years fold into one "earlier" column. */
export const MAX_RELEASE_COLUMNS = 45;

export interface ReleaseColumn<T> {
    /** The year, or null for the column of every older year. */
    year: number | null;
    /** Its dots, from the middle of the column outwards. */
    dots: T[];
}

/**
 * The release-year chart's columns, one per year from the oldest to the
 * newest (empty years kept, so the axis is time), the oldest folded into
 * one "earlier" column when there are too many years to draw. Within a
 * column the dots are ordered by `rank`, best first, and laid out from the
 * middle outwards — so the best sit in the middle and the shape of the
 * column reads the same at any count.
 */
export function releaseColumns<T extends { year: number }>(dots: T[], rank: (dot: T) => number): ReleaseColumn<T>[] {
    if (dots.length === 0) {
        return [];
    }

    const years = dots.map((dot) => dot.year);
    const newest = Math.max(...years);
    const oldest = Math.min(...years);
    const fold = newest - oldest + 1 > MAX_RELEASE_COLUMNS ? newest - MAX_RELEASE_COLUMNS + 2 : null;

    const columns: ReleaseColumn<T>[] = [];

    if (fold !== null) {
        columns.push({ year: null, dots: dots.filter((dot) => dot.year < fold) });
    }

    for (let year = fold ?? oldest; year <= newest; year++) {
        columns.push({ year, dots: dots.filter((dot) => dot.year === year) });
    }

    return columns.map((column) => ({ ...column, dots: [...column.dots].sort((a, b) => rank(b) - rank(a)) }));
}

/** Where the n-th dot of a column sits, counted from the middle: 0, then +1, −1, +2, −2… */
export function middleOut(index: number): number {
    return index % 2 === 0 ? -index / 2 : (index + 1) / 2;
}
