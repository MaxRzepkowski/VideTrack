import { parseDay } from '@/lib/calendar';
import { formatNumber, formatRuntime } from '@/lib/format';
import { mediaTypeDefinition } from '@/lib/mediaTypes';
import { pageLocale } from '@/lib/pageContext';
import { monthName } from '@/lib/statisticsCharts';
import type { ActivityBucket, StatisticsActivity } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

/**
 * The words around the "activity over time" charts: what a column is called,
 * which of them get a tick, and the averages over a period.
 */

const dayFormat = (options: Intl.DateTimeFormatOptions): Intl.DateTimeFormat =>
    new Intl.DateTimeFormat(pageLocale(), { ...options, timeZone: 'UTC' });

/** The days of a week, the first of them given: the week's last day. */
const WEEK_DAYS = 7;

/** Under the axis: "Nov 3" for a day and for a week (its first day), "Jan" for a month, "2019" for a year. */
export function bucketTick(bucket: string, size: ActivityBucket): string {
    switch (size) {
        case 'day':
        case 'week':
            return dayFormat({ day: 'numeric', month: 'short' }).format(parseDay(bucket));
        case 'month':
            return monthName(Number(bucket.slice(5, 7)) - 1);
        case 'year':
            return bucket;
    }
}

/** In a tooltip or a sentence: "Monday, November 3", "November 3 – 9", "June 2025", "2019". */
export function bucketName(bucket: string, size: ActivityBucket): string {
    switch (size) {
        case 'day':
            return dayFormat({ weekday: 'long', day: 'numeric', month: 'long' }).format(parseDay(bucket));
        case 'week': {
            const start = parseDay(bucket);
            const end = new Date(start.getTime());
            end.setUTCDate(end.getUTCDate() + WEEK_DAYS - 1);

            return dayFormat({ day: 'numeric', month: 'long' }).formatRange(start, end);
        }
        case 'month':
            return dayFormat({ month: 'long', year: 'numeric' }).format(parseDay(`${bucket}-01`));
        case 'year':
            return bucket;
    }
}

/** Which ticks a chart of `count` buckets prints so they do not collide: at most `room` of them. */
export function tickEvery(count: number, room: number): (index: number) => boolean {
    const step = Math.max(1, Math.ceil(count / room));

    return (index) => index % step === 0;
}

/**
 * Time spent, said with what filled it: "56h 48min watching and playing".
 * The types are the ones on show. Reading is an estimate at the user's pace,
 * so a time it went into says so: "≈ 80h 12min, reading estimated".
 */
export function timeSpent(minutes: number, readingMinutes: number, types: string[]): string | null {
    const units = new Set(types.map((type) => mediaTypeDefinition(type).progressUnit));
    const watching = units.has(null);
    const playing = units.has('minutes');

    if (readingMinutes >= 1) {
        return trans('statistics.time_estimated', { time: formatRuntime(Math.round(minutes + readingMinutes)) ?? '' });
    }

    const time = formatRuntime(Math.max(1, Math.round(minutes)));

    if (minutes <= 0 || time === null || (!watching && !playing)) {
        return null;
    }

    if (watching && playing) {
        return trans('statistics.time_watching_playing', { time });
    }

    return watching ? trans('statistics.time_watching', { time }) : trans('statistics.time_playing', { time });
}

/** The averages over a period: per month, per week, per day — what was finished and the time it took. */
export function averages(
    activity: StatisticsActivity,
    days: number,
    types: string[],
): { key: string; label: string; value: string; time: string | null }[] {
    if (days <= 0) {
        return [];
    }

    const spans = [
        { key: 'month', label: trans('statistics.average_per_month_label'), days: 365.25 / 12 },
        { key: 'week', label: trans('statistics.average_per_week_label'), days: 7 },
        { key: 'day', label: trans('statistics.average_per_day_label'), days: 1 },
    ];

    return spans.map((span) => {
        const share = span.days / days;
        const count = activity.completions * share;

        return {
            key: span.key,
            label: span.label,
            value: trans('statistics.average_completions', { value: formatNumber(count, 1) }),
            time: timeSpent(activity.watchMinutes * share, activity.readingMinutes * share, types),
        };
    });
}
