import CalendarGrid from '@/components/statistics/CalendarGrid.vue';
import { calendarWeeks } from '@/lib/statisticsCharts';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function grid(from: string, to: string) {
    return mount(CalendarGrid, {
        props: { from, to, days: {}, firstWeekday: 1, color: () => 'var(--color-scale-1)', tooltip: () => '' },
        global: { directives: { tooltip: {} } },
    });
}

const weekOf = (from: string, to: string, day: string): number =>
    calendarWeeks(from, to, {}, 1).weeks.findIndex((week) => week.some((cell) => cell.day === day));

const latest = (wrapper: ReturnType<typeof grid>): number =>
    wrapper.findAll('.mt-1.flex > div').findIndex((week) => week.attributes('data-latest-week') !== undefined);

describe('CalendarGrid', () => {
    it("opens on today's week, not on the empty weeks the year still has", () => {
        // The clock stands at 2026-10-10.
        expect(latest(grid('2026-01-01', '2026-12-31'))).toBe(weekOf('2026-01-01', '2026-12-31', '2026-10-10'));
    });

    it('opens on the last week of a period that is over', () => {
        expect(latest(grid('2025-01-01', '2025-12-31'))).toBe(weekOf('2025-01-01', '2025-12-31', '2025-12-31'));
    });
});
