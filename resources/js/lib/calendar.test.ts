import { addDays, dayDate, dayLabel, groupCalendarEntries, isoWeekday, monthCells, weekStartOf } from '@/lib/calendar';
import { mediaSummary } from '@/test/factories';
import { nth } from '@/test/lists';
import type { CalendarEntry } from '@/types/media';
import { describe, expect, it } from 'vitest';

const episode = (show: string, ref: string): CalendarEntry => ({
    media: mediaSummary({ type: 'episode', ref: `${show}:${ref}` }),
    showTitle: `Show ${show}`,
});
const movie = (ref: string): CalendarEntry => ({ media: mediaSummary({ type: 'movie', ref }), showTitle: null });

describe('groupCalendarEntries', () => {
    it("collapses one show's episodes of the day into one group", () => {
        const groups = groupCalendarEntries([episode('10', '1'), episode('10', '2'), episode('10', '3')]);

        expect(groups).toHaveLength(1);
        expect(nth(groups, 0).key).toBe('show:10');
        expect(nth(groups, 0).entries).toHaveLength(3);
    });

    it('keeps different shows and everything else apart, in first-seen order', () => {
        const groups = groupCalendarEntries([movie('7'), episode('10', '1'), episode('20', '1'), movie('8'), episode('10', '2')]);

        expect(groups.map((group) => group.key)).toEqual(['one:0', 'show:10', 'show:20', 'one:3']);
        expect(nth(groups, 1).entries).toHaveLength(2);
    });

    it('gives an empty day no groups', () => {
        expect(groupCalendarEntries([])).toEqual([]);
    });
});

describe('calendar days', () => {
    it('steps across a month end in UTC and reads ISO weekdays', () => {
        expect(addDays('2026-10-31', 1)).toBe('2026-11-01');
        expect(addDays('2026-03-01', -1)).toBe('2026-02-28');
        expect(isoWeekday('2026-10-11')).toBe(7);
        expect(isoWeekday('2026-10-12')).toBe(1);
    });

    it('opens a week on the day the user chose', () => {
        expect(weekStartOf('monday', 'en-US')).toBe(1);
        expect(weekStartOf('sunday', 'pl')).toBe(7);
    });

    it('squares a month up into whole weeks with the days next door', () => {
        // October 2026 opens on a Thursday and closes on a Saturday.
        const cells = monthCells('2026-10-01', '2026-10-31', 1);

        expect(cells.length % 7).toBe(0);
        expect(cells.slice(0, 4)).toEqual([
            { date: '2026-09-28', filler: true },
            { date: '2026-09-29', filler: true },
            { date: '2026-09-30', filler: true },
            { date: '2026-10-01', filler: false },
        ]);
        expect(cells.at(-1)).toEqual({ date: '2026-11-01', filler: true });
    });

    it('names today as today and says the year of another one', () => {
        expect(dayLabel('2026-10-10', '2026-10-10')).toBe('Today');
        expect(dayLabel('2026-10-12', '2026-10-10')).toBe('Mon');
        expect(dayDate('2026-10-12')).toBe('Oct 12');
        expect(dayDate('2027-01-02', '2026-10-10')).toBe('Jan 2, 2027');
    });
});
