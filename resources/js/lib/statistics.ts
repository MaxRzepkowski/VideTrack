import { addDays, parseDay } from '@/lib/calendar';
import { formatNumber } from '@/lib/format';
import { mediaTypeMeta } from '@/lib/mediaTypes';
import { pageLocale, viewerTimeZone } from '@/lib/pageContext';
import type { PeriodKind, PeriodSpan, PeriodTitles, TypeTally, YearCount } from '@/types/generated';
import { trans, transChoice } from 'laravel-vue-i18n';

/**
 * The statistics page's own rules: its address, how a period is named and
 * which years its axis shows. Days are calendar dates (YYYY-MM-DD) the server
 * already put on the user's clock, so they are formatted in UTC and never
 * shifted again.
 */

/** How many titles, named for the view: "12 titles" across every type, "12 movies" for one. */
export function countTitles(type: string | null, count: number): string {
    return type === null ? transChoice('common.count_title', count, { count: formatNumber(count) }) : mediaTypeMeta(type).count(count);
}

/** What the view's titles are called inside a sentence: "titles" across every type, "movies" for one. */
export function typeNoun(type: string | null): string {
    return type === null ? trans('statistics.titles_word') : mediaTypeMeta(type).plural().toLocaleLowerCase();
}

/** The scope a subtitle names: "all types together", or the one type ("movies"). */
export function scopeName(type: string | null): string {
    return type === null ? trans('statistics.all_types_together') : mediaTypeMeta(type).plural().toLocaleLowerCase();
}

/** What the page is asked for: a year, the whole history, or two days; and a type. */
export interface StatisticsQuery {
    period?: number | Extract<PeriodKind, 'all'> | null;
    from?: string | null;
    to?: string | null;
    type?: string | null;
}

/** The page's query string, without what would be the default anyway. */
export function statisticsParams(query: StatisticsQuery): Record<string, string> {
    const params: Record<string, string> = {};

    if (query.from && query.to) {
        params.from = query.from;
        params.to = query.to;
    } else if (query.period !== undefined && query.period !== null) {
        params.period = String(query.period);
    }

    if (query.type) {
        params.type = query.type;
    }

    return params;
}

/** The query the page stands on, so a change to one part keeps the rest. */
export function currentQuery(period: PeriodSpan, type: string | null): StatisticsQuery {
    switch (period.kind) {
        case 'year':
            return { period: period.year, type };
        case 'all':
            return { period: 'all', type };
        case 'range':
            return { from: period.from, to: period.to, type };
    }
}

function formatDay(day: string, options: Intl.DateTimeFormatOptions): string {
    return new Intl.DateTimeFormat(pageLocale(), { ...options, timeZone: 'UTC' }).format(parseDay(day));
}

/** The period as a heading: "2025", "All time", "12 Mar 2024 – 5 Jan 2025". */
export function periodTitle(period: PeriodSpan): string {
    if (period.kind === 'year' && period.year !== null) {
        return String(period.year);
    }

    if (period.kind === 'range' && period.from !== null && period.to !== null) {
        return rangeLabel(period.from, period.to);
    }

    return trans('statistics.whole_history');
}

/** Two days, short: "12 Mar 2024 – 5 Jan 2025". */
export function rangeLabel(from: string, to: string): string {
    return new Intl.DateTimeFormat(pageLocale(), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' }).formatRange(
        parseDay(from),
        parseDay(to),
    );
}

/**
 * The period's first and last day, beside its active days. A year needs no
 * year in its dates; the whole history does. A range is named by its days
 * already, so it has nothing to add.
 */
export function periodDates(period: PeriodSpan): string | null {
    if (period.from === null || period.to === null || period.kind === 'range') {
        return null;
    }

    const options: Intl.DateTimeFormatOptions =
        period.kind === 'year' ? { day: 'numeric', month: 'long' } : { day: 'numeric', month: 'long', year: 'numeric' };

    return trans('statistics.period_range', { from: formatDay(period.from, options), to: formatDay(period.to, options) });
}

/** A moment's day spelled out with its weekday, on the viewer's clock: "Monday, 6 January". */
export function weekdayDate(timestamp: string, withYear: boolean): string {
    return new Intl.DateTimeFormat(pageLocale(), {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        ...(withYear ? { year: 'numeric' } : {}),
        timeZone: viewerTimeZone(),
    }).format(new Date(timestamp));
}

/** Whole days from one calendar day to another. */
export function daysBetween(from: string, to: string): number {
    return Math.round((parseDay(to).getTime() - parseDay(from).getTime()) / 86_400_000);
}

/**
 * The years the axis shows at once: `size` of them, the newest ones unless
 * the chosen year is older (then it sits second from the right, its next
 * year beside it), moved `offset` windows back. Says whether anything lies
 * before or after the window.
 */
export function yearWindow(
    years: YearCount[],
    anchor: number | null,
    size: number,
    offset = 0,
): { shown: YearCount[]; before: boolean; after: boolean } {
    if (years.length <= size) {
        return { shown: years, before: false, after: false };
    }

    const anchorIndex = anchor === null ? -1 : years.findIndex((entry) => entry.year === anchor);
    const natural = anchorIndex === -1 ? years.length : Math.min(years.length, anchorIndex + 2);
    const end = Math.min(years.length, Math.max(size, natural - offset * size));
    const start = end - size;

    return { shown: years.slice(start, end), before: start > 0, after: end < years.length };
}

/** The ready-made ranges of the range panel, ending today (YYYY-MM-DD). */
export function rangeShortcuts(today: string): { key: string; label: string; from: string; to: string }[] {
    const firstOfMonth = `${today.slice(0, 7)}-01`;
    const previousMonthEnd = addDays(firstOfMonth, -1);
    const yearAgo = parseDay(today);
    yearAgo.setUTCFullYear(yearAgo.getUTCFullYear() - 1);

    return [
        { key: 'last_30_days', label: trans('statistics.last_30_days'), from: addDays(today, -29), to: today },
        { key: 'last_12_months', label: trans('statistics.last_12_months'), from: addDays(yearAgo.toISOString().slice(0, 10), 1), to: today },
        { key: 'year_to_date', label: trans('statistics.year_to_date'), from: `${today.slice(0, 4)}-01-01`, to: today },
        { key: 'previous_month', label: trans('statistics.previous_month'), from: `${previousMonthEnd.slice(0, 7)}-01`, to: previousMonthEnd },
    ].filter((shortcut) => shortcut.from <= shortcut.to && (shortcut.key !== 'previous_month' || firstOfMonth > shortcut.to));
}

/** What a title ended a period as; the four add up to the period's titles. */
export type TitleMark = Exclude<keyof PeriodTitles, 'titles'>;

const MARK_COLORS: Record<TitleMark, string> = {
    completed: 'var(--color-action-watched)',
    inProgress: 'var(--color-status-progress)',
    onHold: 'var(--color-status-onhold)',
    dropped: 'var(--color-status-dropped)',
};

/** Each mark with its count in the page's language: "5 completed", "1 on hold". */
function markCount(mark: TitleMark, count: number): string {
    // `:amount`, not `:count`: transChoice writes the raw number into :count, without the thousands separator.
    const replacements = { amount: formatNumber(count) };

    switch (mark) {
        case 'completed':
            return transChoice('statistics.count_completed', count, replacements);
        case 'inProgress':
            return transChoice('statistics.count_in_progress', count, replacements);
        case 'onHold':
            return transChoice('statistics.count_on_hold', count, replacements);
        case 'dropped':
            return transChoice('statistics.count_dropped', count, replacements);
    }
}

/** The period's titles by mark, in a fixed order, each with its count, said with it, and its colour. */
export function titleMarks(titles: PeriodTitles): { mark: TitleMark; count: number; counted: string; color: string }[] {
    return (['completed', 'inProgress', 'onHold', 'dropped'] as const).map((mark) => ({
        mark,
        count: titles[mark].total,
        counted: markCount(mark, titles[mark].total),
        color: MARK_COLORS[mark],
    }));
}

/** Under this share of the whole, a row's bar still shows as a sliver rather than nothing. */
const SLIVER_PERCENT = 0.6;

/** Below this share, a percentage reads as "<0.1%" rather than rounding to nothing. */
const SMALLEST_SHARE = 0.001;

/**
 * Where the time went, one row per type, the most first: its minutes, its
 * share of the whole as the locale writes it, and its bar's length against
 * the busiest type.
 */
export function timeRows(time: TypeTally): { type: string; minutes: number; share: string; width: number }[] {
    const busiest = Math.max(1, ...time.byType.map((entry) => entry.count));
    const percent = new Intl.NumberFormat(pageLocale(), { style: 'percent', minimumFractionDigits: 1, maximumFractionDigits: 1 });

    return [...time.byType]
        .sort((a, b) => b.count - a.count)
        .map((entry) => {
            const share = time.total === 0 ? 0 : entry.count / time.total;

            return {
                type: entry.type,
                minutes: entry.count,
                share:
                    share > 0 && share < SMALLEST_SHARE
                        ? trans('statistics.share_below', { value: percent.format(SMALLEST_SHARE) })
                        : percent.format(share),
                width: Math.max(SLIVER_PERCENT, (entry.count / busiest) * 100),
            };
        });
}

/** How often an active day came round, as one in so many days: 2 is every other day. Null without any. */
export function activeEvery(days: number, active: number): number | null {
    return active <= 0 || days <= 0 ? null : Math.max(1, Math.round(days / active));
}

/** The rating given most often (the first of a tie), on a scale whose lowest is `lowest`; null with none given. */
export function commonestRating(counts: number[], lowest: number): number | null {
    const most = Math.max(0, ...counts);

    return most === 0 ? null : counts.indexOf(most) + lowest;
}

/** A share as the locale writes a percentage: "87%" / "87 %". */
export function formatPercent(part: number, whole: number): string {
    return new Intl.NumberFormat(pageLocale(), { style: 'percent', maximumFractionDigits: 0 }).format(whole === 0 ? 0 : part / whole);
}

/** A rating with its decimals as the locale writes them: "6,56". */
export function formatRating(value: number): string {
    return new Intl.NumberFormat(pageLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value);
}

/** Names as one list in the page's language, all in lower case: "episodes, movies and games". */
export function lowerList(names: string[]): string {
    const locale = pageLocale();

    return new Intl.ListFormat(locale, { type: 'conjunction' }).format(names.map((name) => name.toLocaleLowerCase(locale)));
}

/**
 * The server rounds a rating difference to two decimals; anything under half
 * a hundredth is that rounding, not a difference.
 */
const SAME_RATING_BELOW = 0.005;

/** The user's rating against everyone else's, in words: "0.76 below others". */
export function communityComparison(delta: number): string {
    const value = new Intl.NumberFormat(pageLocale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Math.abs(delta));

    if (Math.abs(delta) < SAME_RATING_BELOW) {
        return trans('statistics.same_as_others');
    }

    return delta > 0 ? trans('statistics.value_above_others', { value }) : trans('statistics.value_below_others', { value });
}

/** What the genre ranking is ordered and drawn by. */
export type GenreMeasure = 'titles' | 'amount' | 'rating';

/** Ratings a genre's average must rest on before it is ranked by it. */
export const GENRE_RATINGS_MIN = 3;

/** The genres in the order of a measure, with the value its bar is drawn to; a genre too little rated to average drops out of the rating ranking. */
export function rankGenres<T extends { titles: number; amount: number; averageRating: number | null; rated: number }>(
    genres: T[],
    measure: GenreMeasure,
): { genre: T; value: number }[] {
    const value = (genre: T): number | null => {
        switch (measure) {
            case 'titles':
                return genre.titles;
            case 'amount':
                return genre.amount;
            case 'rating':
                return genre.rated >= GENRE_RATINGS_MIN ? genre.averageRating : null;
        }
    };

    return genres
        .map((genre) => ({ genre, value: value(genre) }))
        .filter((entry): entry is { genre: T; value: number } => entry.value !== null)
        .sort((a, b) => b.value - a.value);
}
