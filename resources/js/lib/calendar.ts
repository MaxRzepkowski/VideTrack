import { showRefOf } from '@/lib/hierarchy';
import { pageLocale } from '@/lib/pageContext';
import type { NonEmpty } from '@/types';
import type { CalendarEntry } from '@/types/media';
import { trans } from 'laravel-vue-i18n';

/** A day's releases with the same show's episodes collapsed into one record. */
export interface CalendarEntryGroup {
    key: string;
    /** Never empty: a group exists because an entry does. */
    entries: NonEmpty<CalendarEntry>;
}

/**
 * Collapses a day's releases for the space-constrained calendar surfaces (the
 * dashboard's day columns, the month grid's cells). A whole season dropping at
 * once (Netflix and friends) would otherwise stack eight near-identical records
 * in one narrow column and push everything else out of sight — episodes of the
 * same show on the same day become ONE group covering them all, while anything
 * else stays a group of its own.
 */
export function groupCalendarEntries(entries: CalendarEntry[]): CalendarEntryGroup[] {
    const groups = new Map<string, NonEmpty<CalendarEntry>>();

    entries.forEach((entry, index) => {
        // Only episodes group — and only per show. Anything else gets a key
        // nothing can collide with.
        const key = entry.showTitle !== null ? `show:${showRefOf(entry.media.ref)}` : `one:${index}`;

        const grouped = groups.get(key);

        groups.set(key, grouped ? [...grouped, entry] : [entry]);
    });

    return [...groups.entries()].map(([key, grouped]) => ({ key, entries: grouped }));
}

// Calendar days are plain Y-m-d: read and written in UTC, so no timezone ever
// moves one onto the square next door.

/** The day `n` days after (or before) a Y-m-d date. */
/** A calendar date (YYYY-MM-DD) as the instant of its midnight in UTC, for arithmetic and for formatting in UTC. */
export function parseDay(date: string): Date {
    return new Date(`${date}T00:00:00Z`);
}

export function addDays(date: string, n: number): string {
    const day = parseDay(date);
    day.setUTCDate(day.getUTCDate() + n);

    return day.toISOString().slice(0, 10);
}

/** ISO weekday of a Y-m-d date: 1 = Monday … 7 = Sunday. */
export function isoWeekday(date: string): number {
    return new Date(`${date}T00:00:00Z`).getUTCDay() || 7;
}

/**
 * Which weekday opens a week (ISO, 1 = Monday … 7 = Sunday): the user's own
 * setting, else the locale's answer — Polish weeks open on Monday, American
 * ones on Sunday. A browser with no week data (or only the older `weekInfo`
 * accessor) falls back to Monday.
 */
export function weekStartOf(setting: 'auto' | 'monday' | 'sunday', locale: string): number {
    if (setting === 'monday') {
        return 1;
    }

    if (setting === 'sunday') {
        return 7;
    }

    const info = new Intl.Locale(locale) as Intl.Locale & { getWeekInfo?: () => { firstDay: number }; weekInfo?: { firstDay: number } };

    return info.getWeekInfo?.().firstDay ?? info.weekInfo?.firstDay ?? 1;
}

/** One square of a month: its date, and whether it belongs to the month next door, squaring up a week. */
export interface MonthCell {
    date: string;
    filler: boolean;
}

/**
 * Every day from `start` to `end`, padded with the days around them until the
 * first lands on its own weekday and the last week reaches its end.
 */
export function monthCells(start: string, end: string, weekStart: number): MonthCell[] {
    const cells: MonthCell[] = [];
    const lead = (isoWeekday(start) - weekStart + 7) % 7;

    for (let i = lead; i > 0; i--) {
        cells.push({ date: addDays(start, -i), filler: true });
    }

    for (let date = start; date <= end; date = addDays(date, 1)) {
        cells.push({ date, filler: false });
    }

    const trail = (7 - (cells.length % 7)) % 7;

    for (let i = 1; i <= trail; i++) {
        cells.push({ date: addDays(end, i), filler: true });
    }

    return cells;
}

/** The name a day goes by over its column: "Today", else its weekday ("Mon"). */
export function dayLabel(date: string, today: string): string {
    return date === today
        ? trans('common.today')
        : new Intl.DateTimeFormat(pageLocale(), { weekday: 'short', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** The date under that name ("Oct 12"); against `today`, a day of another year says its year too. */
export function dayDate(date: string, today: string | null = null): string {
    const year = today !== null && date.slice(0, 4) !== today.slice(0, 4) ? 'numeric' : undefined;

    return new Intl.DateTimeFormat(pageLocale(), { month: 'short', day: 'numeric', year, timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}
