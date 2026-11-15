import {
    countryName,
    dateTimeInputInstant,
    durationTerms,
    episodeCode,
    formatCalendarDate,
    formatCountOrNull,
    formatDateTime,
    formatDayKey,
    formatMoney,
    formatNumber,
    formatReleaseDate,
    formatRuntime,
    formatTime,
    formatTimestampDate,
    formatTotalRuntime,
    formatWatchTime,
    getInitials,
    localDateKey,
    localDateTimeInput,
    relativeTime,
    splitAtPlaceholder,
    todayDateKey,
    withCount,
    yearsBetween,
} from '@/lib/format';
import { setPageProps } from '@/test/page';
import { afterEach, describe, expect, it, vi } from 'vitest';

function prefer(preferences: Record<string, unknown>): void {
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences } } });
}

afterEach(() => vi.useRealTimers());

describe('codes and the locale', () => {
    it('pads an episode code', () => {
        expect(episodeCode(2, 9)).toBe('S02E09');
        expect(episodeCode(12, 105)).toBe('S12E105');
    });

    it('names a country in the UI language and falls back to the code', () => {
        expect(countryName('PL')).toBe('Poland');
        setPageProps({ locale: 'pl' });
        expect(countryName('PL')).toBe('Polska');
        expect(countryName('not-a-code')).toBe('not-a-code');
    });
});

describe('dates and times', () => {
    it('counts the years to a day the way a birthday does', () => {
        expect(yearsBetween('1964-09-02', '2026-09-01')).toBe(61);
        expect(yearsBetween('1964-09-02', '2026-09-02')).toBe(62);
        expect(yearsBetween('2000-02-29', '2026-02-28')).toBe(25);
        expect(yearsBetween('2000-02-29', '2026-03-01')).toBe(26);
    });

    it('never shifts a calendar date, wherever the viewer is', () => {
        prefer({ timezone: 'America/Los_Angeles' });

        expect(formatCalendarDate('1999-03-30')).toBe('March 30, 1999');
        expect(formatDayKey('2026-10-10')).toBe('Saturday, October 10, 2026');
        expect(formatCalendarDate(null)).toBeNull();
        expect(formatDayKey(null)).toBeNull();
    });

    it('says a release date only as far as it is known, from the stand-in day it arrives with', () => {
        prefer({ timezone: 'America/Los_Angeles' });

        expect(formatReleaseDate('2028-03-12')).toBe('March 12, 2028');
        expect(formatReleaseDate('2028-03-12', 'day')).toBe('March 12, 2028');
        expect(formatReleaseDate('2028-03-01', 'month')).toBe('March 2028');
        expect(formatReleaseDate('2028-03-31', 'quarter')).toBe('Q1 2028');
        expect(formatReleaseDate('2028-12-31', 'quarter')).toBe('Q4 2028');
        expect(formatReleaseDate('2028-12-31', 'year')).toBe('2028');
        expect(formatReleaseDate('2028-01-01', 'year')).toBe('2028');
        expect(formatReleaseDate(null, 'year')).toBeNull();

        setPageProps({ locale: 'pl' });
        expect(formatReleaseDate('2028-03-01', 'month')).toBe('marzec 2028');
    });

    it("puts a timestamp on the viewer's own calendar", () => {
        prefer({ timezone: 'Europe/Warsaw' });

        expect(formatTimestampDate('2026-10-10T23:30:00Z')).toBe('October 11, 2026');
        expect(localDateKey('2026-10-10T23:30:00Z')).toBe('2026-10-11');

        prefer({ timezone: 'America/New_York' });
        expect(localDateKey('2026-10-10T23:30:00Z')).toBe('2026-10-10');
        expect(localDateKey(null)).toBe('');
    });

    it('follows the 12- or 24-hour preference, else the locale', () => {
        prefer({ timezone: 'UTC', time_format: '24' });
        expect(formatTime('2026-10-10T21:05:00Z')).toBe('21:05');

        prefer({ timezone: 'UTC', time_format: '12' });
        expect(formatTime('2026-10-10T21:05:00Z')).toBe('9:05 PM');

        prefer({ timezone: 'UTC', time_format: 'auto' });
        expect(formatDateTime('2026-10-10T21:05:00Z')).toBe('Oct 10, 2026, 9:05 PM');
        expect(formatTime(null)).toBeNull();
        expect(formatDateTime(null)).toBeNull();
    });

    it('reads today in the viewer timezone', () => {
        vi.useFakeTimers({ now: new Date('2026-10-10T23:30:00Z') });
        prefer({ timezone: 'Asia/Tokyo' });

        expect(todayDateKey()).toBe('2026-10-11');
    });

    it('says how long ago in the largest whole unit', () => {
        vi.useFakeTimers({ now: new Date('2026-10-10T12:00:00Z') });

        expect(relativeTime('2026-10-10T10:00:00Z')).toBe('2 hours ago');
        expect(relativeTime('2026-10-09T12:00:00Z')).toBe('yesterday');
        expect(relativeTime('2026-10-10T11:59:40Z')).toBe('this minute');
        expect(relativeTime('2026-10-24T12:00:00Z')).toBe('in 2 weeks');
        expect(relativeTime(null)).toBeNull();
    });
});

describe('datetime-local fields', () => {
    it("holds the browser's wall time to the minute when the viewer chose no timezone", () => {
        prefer({});
        expect(localDateTimeInput(new Date(2026, 9, 10, 14, 5, 59))).toBe('2026-10-10T14:05');
        expect(dateTimeInputInstant('2026-10-10T14:05')).toEqual(new Date(2026, 9, 10, 14, 5));
    });

    it("holds and reads back wall time in the viewer's timezone, where history shows it", () => {
        prefer({ timezone: 'America/New_York' });

        expect(localDateTimeInput(new Date('2026-10-10T18:05:59Z'))).toBe('2026-10-10T14:05');
        expect(dateTimeInputInstant('2026-10-10T20:00')?.toISOString()).toBe('2026-10-11T00:00:00.000Z');
        // Either side of the clock change, each day with its own offset.
        expect(dateTimeInputInstant('2026-11-01T00:30')?.toISOString()).toBe('2026-11-01T04:30:00.000Z');
        expect(dateTimeInputInstant('2026-11-01T12:00')?.toISOString()).toBe('2026-11-01T17:00:00.000Z');
    });

    it('reads nothing out of a value that is not a moment', () => {
        expect(dateTimeInputInstant('')).toBeNull();
        expect(dateTimeInputInstant('2026-10-10')).toBeNull();
    });
});

describe('durations and numbers', () => {
    it('writes a runtime as hours and minutes', () => {
        expect(formatRuntime(136)).toBe('2h 16min');
        expect(formatRuntime(45)).toBe('45min');
        expect(formatRuntime(0)).toBeNull();
        expect(formatRuntime(null)).toBeNull();
    });

    it('counts a whole item in days once it passes one, dropping empty parts', () => {
        expect(formatTotalRuntime(90)).toBe('1h 30min');
        expect(formatTotalRuntime(3 * 1440 + 20)).toBe('3d 20min');
        expect(formatTotalRuntime(1440 + 60)).toBe('1d 1h');
        expect(formatTotalRuntime(null)).toBeNull();
    });

    it('spells out watch time with the plural of each unit', () => {
        expect(formatWatchTime(3 * 1440 + 7 * 60 + 12)).toBe('3 days 7 hours 12 minutes');
        expect(formatWatchTime(1440 + 1)).toBe('1 day 1 minute');
        expect(formatWatchTime(0)).toBe('0 minutes');
        expect(durationTerms(60)).toEqual([{ value: '1', unit: 'hour' }]);
    });

    it('formats numbers and money for the UI language', () => {
        expect(formatNumber(1234567)).toBe('1,234,567');
        expect(formatNumber(12.5, 1)).toBe('12.5');
        expect(formatMoney(150000000)).toBe('$150,000,000');
        expect(formatMoney(0)).toBeNull();
        expect(formatMoney(undefined)).toBeNull();

        setPageProps({ locale: 'pl' });
        expect(formatNumber(12.5, 1)).toBe('12,5');
        expect(formatNumber(1234567)).toBe('1 234 567');
    });
});

describe('getInitials', () => {
    it('takes the first and the last name, whatever the spacing', () => {
        expect(getInitials('Jan Kowalski')).toBe('JK');
        expect(getInitials('anna maria nowak')).toBe('AN');
        expect(getInitials('Jan  Kowalski ')).toBe('JK');
        expect(getInitials('cher')).toBe('C');
    });

    it('has nothing for no name', () => {
        expect(getInitials('')).toBe('');
        expect(getInitials(undefined)).toBe('');
    });
});

describe('splitAtPlaceholder', () => {
    it("cuts a sentence where its element goes, in the language's own order", () => {
        expect(splitAtPlaceholder('Or, return to :login', ':login')).toEqual({ before: 'Or, return to ', after: '' });
        expect(splitAtPlaceholder('Zobacz :title teraz', ':title')).toEqual({ before: 'Zobacz ', after: ' teraz' });
    });

    it('says when the sentence carries no placeholder', () => {
        expect(splitAtPlaceholder('Plain text', ':license')).toEqual({ before: 'Plain text', after: null });
    });
});

describe('formatCountOrNull', () => {
    it('formats a count that came, and says nothing for one that did not', () => {
        expect(formatCountOrNull(1200)).toBe(formatNumber(1200));
        expect(formatCountOrNull(null)).toBeNull();
        expect(formatCountOrNull('12')).toBeNull();
    });
});

describe('withCount', () => {
    it('words a label with its count through one translation', () => {
        expect(withCount('Movies', 12)).toBe('Movies (12)');
    });
});
