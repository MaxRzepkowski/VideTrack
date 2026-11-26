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

/** A language tag as the language's name in the UI language; the tag itself where Intl does not know it. */
export function languageName(tag: string): string {
    try {
        return new Intl.DisplayNames([pageLocale()], { type: 'language' }).of(tag) ?? tag;
    } catch {
        return tag;
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

/**
 * A release date as short as a button has room for: "Dec 15" for a known day,
 * else as far as it is known ("March 2028", "2028"). Calendar date, so UTC.
 */
export function formatShortReleaseDate(date: string, precision: DatePrecision = 'day'): string {
    if (precision !== 'day') {
        return formatReleaseDate(date, precision) ?? date;
    }

    return new Intl.DateTimeFormat(pageLocale(), { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${date}T00:00:00Z`));
}

/** The day of a moment as short as a button has room for ("Nov 20"), on the viewer's calendar. */
export function formatShortDay(instant: Date): string {
    return new Intl.DateTimeFormat(pageLocale(), { month: 'short', day: 'numeric', timeZone: viewerTimeZone() }).format(instant);
}

/** From one timestamp's day to another's, on the viewer's calendar; one day when both fall on it. */
export function formatTimestampRange(from: string, to: string): string {
    const format = new Intl.DateTimeFormat(pageLocale(), { dateStyle: 'long', timeZone: viewerTimeZone() });

    return format.formatRange(new Date(from), new Date(to));
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

/** A whole hour of the viewer's day, as their time format writes it: "8:00" / "8:00 AM". */
export function formatHourOfDay(hour: number): string {
    return new Intl.DateTimeFormat(pageLocale(), { timeStyle: 'short', timeZone: 'UTC', ...timeFormatOptions() }).format(Date.UTC(2000, 0, 1, hour));
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
export function formatDayKey(day: string): string;
export function formatDayKey(day: string | null): string | null;
export function formatDayKey(day: string | null): string | null {
    if (day === null) {
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
 * Duration terms side by side the language's way: "2h 16min", "3 days 7 hours";
 * a language that writes them with no space between says so in its keys.
 */
function joinTerms(terms: string[]): string {
    const [first, second, third] = terms;

    if (first === undefined) {
        throw new Error('A duration has at least one term.');
    }

    if (second === undefined) {
        return first;
    }

    return third === undefined ? trans('media.duration_two', { first, second }) : trans('media.duration_three', { first, second, third });
}

/** A number and a compact unit as one term: "2h", "16min". */
function shortTerm(value: string, unit: string): string {
    return trans('media.duration_term_short', { value, unit });
}

/** Hours as a compact term, a decimal allowed: "12,5h". */
export function formatShortHours(hours: number): string {
    return shortTerm(formatNumber(hours, 1), trans('media.hours_short'));
}

/**
 * Runtime in minutes as "2h 16min": the numbers take locale formatting (a
 * decimal never shows here, but large hour counts still get the locale
 * thousands separator), the units and their order come from the language.
 */
export function formatRuntime(minutes: number | null): string | null {
    if (!minutes) {
        return null;
    }

    const h = Math.floor(minutes / 60);
    const m = minutes % 60;

    return h > 0
        ? joinTerms([shortTerm(formatNumber(h), trans('media.hours_short')), shortTerm(String(m), trans('media.minutes_short'))])
        : shortTerm(String(m), trans('media.minutes_short'));
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

    const parts = [shortTerm(formatNumber(Math.floor(minutes / 1440)), trans('media.days_short'))];
    const h = Math.floor((minutes % 1440) / 60);
    const m = minutes % 60;

    if (h > 0) {
        parts.push(shortTerm(String(h), trans('media.hours_short')));
    }

    if (m > 0) {
        parts.push(shortTerm(String(m), trans('media.minutes_short')));
    }

    return joinTerms(parts);
}

/**
 * One term of an amount as its language writes it ("3 days", "320 pages",
 * "챕터 320개"): the number, and the words before and after it, spaces
 * included, so a page can set the number apart without moving or spacing it
 * by a rule of its own.
 */
export type AmountTerm = { value: string; before: string; after: string };

/** The term a number makes in a translated template, cut where the placeholder stands. */
export function amountTerm(value: string, template: string, placeholder: string): AmountTerm {
    const { before, after } = splitAtPlaceholder(template, placeholder);

    if (after === null) {
        throw new Error(`The template [${template}] has no ${placeholder} for its number.`);
    }

    return { value, before, after };
}

/**
 * A span of watching, spelled out as "3 days 7 hours 12 minutes" — the format
 * the dashboard's stat tiles set, so every total the app quotes reads the same
 * way. Zero terms are dropped ("3 days 20 minutes"); nothing at all still says
 * "0 minutes". The unit comes from a plural key of its own rather than from
 * subtracting the number out of ":count days" — `transChoice` writes the raw
 * number into `:count` itself, so the two would not always match.
 */
export function durationTerms(minutes: number): AmountTerm[] {
    const term = (count: number, unit: string): AmountTerm => amountTerm(String(count), trans('media.duration_term', { unit }), ':value');

    const days = Math.floor(minutes / (60 * 24));
    const hours = Math.floor((minutes % (60 * 24)) / 60);
    const rest = minutes % 60;

    const terms: AmountTerm[] = [];
    if (days > 0) terms.push(term(days, transChoice('media.day', days)));
    if (hours > 0) terms.push(term(hours, transChoice('media.hour', hours)));
    if (rest > 0 || terms.length === 0) terms.push(term(rest, transChoice('media.minute', rest)));

    return terms;
}

/**
 * Whether the language puts a space between the terms of a duration
 * ("3 days 7 hours") or none ("3日7時間"), read from the key that joins them.
 */
export function durationTermsSpaced(): boolean {
    return /\s/.test(trans('media.duration_two').replace(':first', '').replace(':second', ''));
}

/** {@see durationTerms} as one string, for places that cannot set the terms apart. */
export function formatWatchTime(minutes: number): string {
    return joinTerms(durationTerms(minutes).map((term) => `${term.before}${term.value}${term.after}`));
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

/**
 * The running version as a footer shows it: a release number gets its "v"
 * ("1.4.0" → "v1.4.0"); a build label ("dev", "main-…") stays as it is.
 */
export function versionLabel(version: string): string {
    return /^\d/.test(version) ? `v${version}` : version;
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

/**
 * A fact cut before its last word, so the word can be held on one line with
 * whatever is drawn after it (a rule between facts). `head` keeps its
 * trailing space; a single word is all `last`.
 */
export function splitLastWord(text: string): { head: string; last: string } {
    const at = text.lastIndexOf(' ') + 1;

    return { head: text.slice(0, at), last: text.slice(at) };
}

/** A count the provider may or may not have sent, formatted; null when it sent none. */
export function formatCountOrNull(value: unknown): string | null {
    return typeof value === 'number' ? formatNumber(value) : null;
}

/**
 * The years a series ran: "1989–2021", "Since 1989" while it still runs, the
 * start year alone when it ended without a known end.
 */
export function formatYearSpan(startDate: string | null, endDate: string | null, stillRunning: boolean): string | null {
    if (!startDate) {
        return null;
    }

    const from = startDate.slice(0, 4);
    const to = endDate?.slice(0, 4) ?? null;

    if (to !== null) {
        return to === from ? from : `${from}–${to}`;
    }

    return stillRunning ? trans('media.since_year', { year: from }) : from;
}

/** How many other names a title page lists: past a handful they read as noise. */
export const ALTERNATIVE_TITLES_SHOWN = 8;

/** The other names a title goes by as one detail line, the first few of them; null when it has none. */
export function alternativeTitlesLine(titles: string[] | null | undefined): string | null {
    return titles?.slice(0, ALTERNATIVE_TITLES_SHOWN).join(', ') || null;
}

/** A label with how many it holds ("Movies (12)"), worded by the translation. */
export function withCount(label: string, count: number): string {
    return trans('common.label_with_count', { label, count: String(count) });
}
