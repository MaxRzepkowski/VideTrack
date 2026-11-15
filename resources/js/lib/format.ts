import { pageLocale, viewerTimeFormat, viewerTimeZone } from '@/lib/pageContext';
import type { DatePrecision } from '@/types/generated';
import { trans, transChoice } from 'laravel-vue-i18n';

/** Formats an episode code like S02E09. */
export function episodeCode(season: number, episode: number): string {
    const pad = (n: number) => String(n).padStart(2, '0');

    return `S${pad(season)}E${pad(episode)}`;
}

/**
 * An ISO 3166-1 code as a country name in the UI language. Falls back to the
 * code itself: Intl throws on codes it does not recognise, and a bare "SU" on
 * screen beats an empty row.
 */
export function countryName(code: string): string {
    try {
        return new Intl.DisplayNames([pageLocale()], { type: 'region' }).of(code) ?? code;
    } catch {
        return code;
    }
}

/**
 * Hour-cycle override from the user's "Time format" preference: 24 → 24-hour,
 * 12 → 12-hour, anything else (auto) → let the UI locale decide. Combines
 * cleanly with `timeStyle`.
 */
function timeFormatOptions(): { hour12?: boolean } {
    const format = viewerTimeFormat();

    if (format === '24') {
        return { hour12: false };
    }

    if (format === '12') {
        return { hour12: true };
    }

    return {};
}

/** A moment's wall time in the viewer's timezone, field by field. */
function wallTime(date: Date): Record<'year' | 'month' | 'day' | 'hour' | 'minute' | 'second', string> {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: viewerTimeZone(),
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const part = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? '00';

    return { year: part('year'), month: part('month'), day: part('day'), hour: part('hour'), minute: part('minute'), second: part('second') };
}

/**
 * A moment as a `datetime-local` field holds it: wall time in the viewer's
 * timezone, to the minute — the zone history shows its moments in, so the
 * field and the entry it writes read the same hour.
 */
export function localDateTimeInput(date: Date = new Date()): string {
    const { year, month, day, hour, minute } = wallTime(date);

    return `${year}-${month}-${day}T${hour}:${minute}`;
}

/** How far the viewer's clock runs ahead of UTC at this moment, in milliseconds. */
function zoneOffset(at: number): number {
    const { year, month, day, hour, minute, second } = wallTime(new Date(at));

    return Date.UTC(Number(year), Number(month) - 1, Number(day), Number(hour), Number(minute), Number(second)) - Math.floor(at / 1000) * 1000;
}

/**
 * The moment a `datetime-local` value names, read as wall time in the
 * viewer's timezone ({@see localDateTimeInput}). Null when it is not one.
 */
export function dateTimeInputInstant(value: string): Date | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);

    if (!match) {
        return null;
    }

    const [, year, month, day, hour, minute] = match.map(Number) as [number, number, number, number, number, number];
    const asUtc = Date.UTC(year, month - 1, day, hour, minute);

    // The offset is the zone's at the moment itself, which a clock change can
    // put on the other side of the first guess.
    const instant = new Date(asUtc - zoneOffset(asUtc - zoneOffset(asUtc)));

    return Number.isNaN(instant.getTime()) ? null : instant;
}

/** Local calendar date (YYYY-MM-DD) of a timestamp in the viewer's timezone — for day grouping. */
export function localDateKey(date: string | null): string {
    if (!date) {
        return '';
    }

    // Built from its parts rather than trusting one locale's short date to read
    // YYYY-MM-DD: that pattern is locale data, and it may change under us.
    const parts = new Intl.DateTimeFormat('en-US', { timeZone: viewerTimeZone(), year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(
        new Date(date),
    );
    const part = (type: Intl.DateTimeFormatPartTypes): string => parts.find((candidate) => candidate.type === type)?.value ?? '';

    return `${part('year')}-${part('month')}-${part('day')}`;
}

/** Today as a calendar date (YYYY-MM-DD) in the viewer's timezone, comparable with release dates. */
export function todayDateKey(): string {
    return localDateKey(new Date().toISOString());
}

/**
 * Whole years from one calendar date (YYYY-MM-DD) to another, as a birthday
 * counts them: the year turns on the day, not before. Read off the digits,
 * so no timezone can move either day.
 */
export function yearsBetween(from: string, to: string): number {
    const years = Number(to.slice(0, 4)) - Number(from.slice(0, 4));

    return to.slice(5, 10) < from.slice(5, 10) ? years - 1 : years;
}

/**
 * Localized long calendar date (YYYY-MM-DD), e.g. "March 30, 1999" / "30 marca 1999".
 * Formatted in UTC on purpose: the value already names the day, and a viewer
 * west of UTC would otherwise see the day before.
 */
export function formatCalendarDate(date: string | null): string | null {
    if (!date) {
        return null;
    }

    return new Intl.DateTimeFormat(pageLocale(), { dateStyle: 'long', timeZone: 'UTC' }).format(new Date(date));
}

/**
 * A release date said only as far as it is known: the long day, or "March
 * 2028", "Q1 2028", "2028" when the provider names no day (the date it sends
 * then is a stand-in). Calendar date, so UTC, as `formatCalendarDate`.
 */
export function formatReleaseDate(date: string | null, precision: DatePrecision = 'day'): string | null {
    if (!date) {
        return null;
    }

    const day = new Date(date);

    switch (precision) {
        case 'day':
            return formatCalendarDate(date);
        case 'month':
            return new Intl.DateTimeFormat(pageLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(day);
        case 'quarter':
            return trans('common.date_quarter', {
                quarter: String(Math.floor(day.getUTCMonth() / 3) + 1),
                year: String(day.getUTCFullYear()),
            });
        case 'year':
            return String(day.getUTCFullYear());
    }
}

/** Localized long date of a timestamp (ISO 8601 with offset), on the viewer's calendar. */
export function formatTimestampDate(timestamp: string | null): string | null {
    if (!timestamp) {
        return null;
    }

    return new Intl.DateTimeFormat(pageLocale(), { dateStyle: 'long', timeZone: viewerTimeZone() }).format(new Date(timestamp));
}

/** Localized date + time in the viewer's timezone, e.g. "Jul 11, 2026, 9:30 PM" / "11 lip 2026, 21:30". */
export function formatDateTime(date: string | null): string | null {
    if (!date) {
        return null;
    }

    return new Intl.DateTimeFormat(pageLocale(), {
        dateStyle: 'medium',
        timeStyle: 'short',
        timeZone: viewerTimeZone(),
        ...timeFormatOptions(),
    }).format(new Date(date));
}

/** Localized time-of-day only, in the viewer's timezone, e.g. "9:00 PM" / "21:00". */
export function formatTime(datetime: string | null): string | null {
    if (!datetime) {
        return null;
    }

    return new Intl.DateTimeFormat(pageLocale(), { timeStyle: 'short', timeZone: viewerTimeZone(), ...timeFormatOptions() }).format(
        new Date(datetime),
    );
}

/**
 * Localized header for a server-computed day key (YYYY-MM-DD). Formatted in
 * UTC on purpose: the key already encodes the right calendar day, so no
 * timezone may shift it again.
 */
export function formatDayKey(day: string | null): string | null {
    if (!day) {
        return null;
    }

    return new Intl.DateTimeFormat(pageLocale(), {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(`${day}T00:00:00Z`));
}

/** Localized relative time, e.g. "2 hours ago" / "2 godziny temu". */
export function relativeTime(date: string | null): string | null {
    if (!date) {
        return null;
    }

    const seconds = Math.round((new Date(date).getTime() - Date.now()) / 1000);
    const steps: [Intl.RelativeTimeFormatUnit, number][] = [
        ['year', 60 * 60 * 24 * 365],
        ['month', 60 * 60 * 24 * 30],
        ['week', 60 * 60 * 24 * 7],
        ['day', 60 * 60 * 24],
        ['hour', 60 * 60],
        ['minute', 60],
    ];
    const format = new Intl.RelativeTimeFormat(pageLocale(), { numeric: 'auto' });

    for (const [unit, size] of steps) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.trunc(seconds / size), unit);
        }
    }

    return format.format(0, 'minute');
}

/**
 * Runtime in minutes as "2h 16min" — the compact units read the same in both
 * languages, so only the numbers need locale formatting (a decimal never shows
 * here, but large hour counts still get the locale thousands separator).
 */
export function formatRuntime(minutes: number | null): string | null {
    if (!minutes) {
        return null;
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return h > 0 ? `${formatNumber(h)}h ${m}min` : `${m}min`;
}

/**
 * Whole-item watch time, as "12d 3h 25min" — anything past a day reads in days,
 * because "1247h" tells nobody how long a series really is. Below 24h it is
 * exactly formatRuntime, so short items look the same everywhere. Zero parts
 * are dropped ("3d 20min" when the hours land empty).
 */
export function formatTotalRuntime(minutes: number | null): string | null {
    if (!minutes) {
        return null;
    }

    if (minutes < 24 * 60) {
        return formatRuntime(minutes);
    }

    const parts = [`${formatNumber(Math.floor(minutes / 1440))}d`];
    const h = Math.floor((minutes % 1440) / 60);
    const m = minutes % 60;

    if (h > 0) {
        parts.push(`${h}h`);
    }

    if (m > 0) {
        parts.push(`${m}min`);
    }

    return parts.join(' ');
}

/** One term of a spelled-out duration: the number, and its own unit word. */
export type DurationTerm = { value: string; unit: string };

/**
 * A span of watching, spelled out as "3 days 7 hours 12 minutes" — the format
 * the dashboard's stat tiles set, so every total the app quotes reads the same
 * way. Zero terms are dropped ("3 days 20 minutes"); nothing at all still says
 * "0 minutes". The unit comes from a plural key of its own rather than from
 * subtracting the number out of ":count days" — `transChoice` writes the raw
 * number into `:count` itself, so the two would not always match.
 */
export function durationTerms(minutes: number): DurationTerm[] {
    const term = (count: number, unit: string) => ({ value: String(count), unit });

    const days = Math.floor(minutes / (60 * 24));
    const hours = Math.floor((minutes % (60 * 24)) / 60);
    const rest = minutes % 60;

    const terms = [];
    if (days > 0) terms.push(term(days, transChoice('media.day', days)));
    if (hours > 0) terms.push(term(hours, transChoice('media.hour', hours)));
    if (rest > 0 || terms.length === 0) terms.push(term(rest, transChoice('media.minute', rest)));

    return terms;
}

/** {@see durationTerms} as one string, for places that cannot set the terms apart. */
export function formatWatchTime(minutes: number): string {
    return durationTerms(minutes)
        .map((term) => `${term.value} ${term.unit}`)
        .join(' ');
}

/** USD amounts from TMDB (budget / revenue). */
export function formatMoney(amount: number | null | undefined): string | null {
    if (!amount) {
        return null;
    }

    return new Intl.NumberFormat(pageLocale(), { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(amount);
}

/**
 * Locale-aware plain number: correct thousands and decimal separators for the
 * UI language (a Polish user sees "1 000 000" and "12,5", not "1,000,000" /
 * "12.5"). Use everywhere a raw count or decimal is shown.
 */
export function formatNumber(value: number, maximumFractionDigits = 0): string {
    return new Intl.NumberFormat(pageLocale(), { maximumFractionDigits }).format(value);
}

/** A name's initials for an avatar without a picture: the first and the last word's first letter. */
export function getInitials(fullName?: string): string {
    const names = (fullName ?? '')
        .trim()
        .split(/\s+/)
        .filter((name) => name !== '');
    const first = names[0] ?? '';
    const last = names.length > 1 ? (names[names.length - 1] ?? '') : '';

    return `${first.charAt(0)}${last.charAt(0)}`.toUpperCase();
}

/**
 * One translated sentence cut where an element goes (a link, a bold title),
 * so each language keeps its own word order around it. `after` is null when
 * the sentence does not carry the placeholder.
 */
export function splitAtPlaceholder(text: string, placeholder: string): { before: string; after: string | null } {
    const at = text.indexOf(placeholder);

    return at === -1 ? { before: text, after: null } : { before: text.slice(0, at), after: text.slice(at + placeholder.length) };
}

/** A count the provider may or may not have sent, formatted; null when it sent none. */
export function formatCountOrNull(value: unknown): string | null {
    return typeof value === 'number' ? formatNumber(value) : null;
}

/** A label with how many it holds ("Movies (12)"), worded by the translation. */
export function withCount(label: string, count: number): string {
    return trans('common.label_with_count', { label, count: String(count) });
}
