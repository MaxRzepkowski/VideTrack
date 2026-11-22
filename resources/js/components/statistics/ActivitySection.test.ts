import ActivitySection from '@/components/statistics/ActivitySection.vue';
import ColumnChart from '@/components/statistics/ColumnChart.vue';
import DayHeatmap from '@/components/statistics/DayHeatmap.vue';
import MonthGrid from '@/components/statistics/MonthGrid.vue';
import { useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { PeriodSpan, StatisticsActivity } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const months = Array.from({ length: 12 }, (_, index) => `2025-${String(index + 1).padStart(2, '0')}`);
const year: PeriodSpan = { kind: 'year', year: 2025, from: '2025-01-01', to: '2025-12-31', days: 365 };

const activity = (overrides: Partial<StatisticsActivity> = {}): StatisticsActivity => ({
    bucket: 'month',
    buckets: months,
    series: [
        { type: 'show', counts: [5, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 3] },
        { type: 'movie', counts: [0, 0, 0, 0, 0, 13, 0, 0, 0, 0, 0, 1] },
        { type: 'game', counts: Array.from({ length: 12 }, () => 0) },
    ],
    completions: 22,
    watchMinutes: 1200,
    readingMinutes: 0,
    days: { '2025-06-12': 2, '2025-12-31': 9 },
    months: null,
    weekdays: [1, 2, 3, 4, 5, 6, 9],
    hours: Array.from({ length: 24 }, (_, hour) => (hour === 21 ? 7 : 1)),
    busiestDay: { day: '2025-12-31', count: 9 },
    firstWeekday: 1,
    ...overrides,
});

const mountSection = (props: { activity: StatisticsActivity; period?: PeriodSpan; type?: string | null }) =>
    mount(ActivitySection, { props: { period: year, type: null, ...props } });

describe('ActivitySection', () => {
    it('draws a chart per type, an empty one for a type with nothing finished', () => {
        const wrapper = mountSection({ activity: activity() });
        const text = wrapper.text();

        expect(text).toContain('Completed each month, per type');
        expect(text).toContain('Games');
        expect(text).toContain('Active days: 2 of 365; most on December 31, 2025 (9)');
        expect(text).toContain('Most: Sunday (9)');
        // Three types, and the hours.
        expect(wrapper.findAllComponents(ColumnChart)).toHaveLength(4);
        expect(wrapper.findComponent(DayHeatmap).exists()).toBe(true);
    });

    it('tells screen readers what the day map shows, month by month', () => {
        const rows = mountSection({ activity: activity() })
            .findComponent(DayHeatmap)
            .findAll('tbody tr')
            .map((row) => row.text());

        expect(rows).toHaveLength(12);
        expect(rows[5]).toMatch(/June 2025\s*2 completed\s*1 active day/);
        expect(rows[0]).toMatch(/January 2025\s*0 completed\s*0 active days/);
    });

    it("gives one type's view one chart that names its busiest month", () => {
        const text = mountSection({
            activity: activity({ series: [{ type: 'movie', counts: [0, 0, 0, 0, 0, 13, 0, 0, 0, 0, 0, 1] }] }),
            type: 'movie',
        }).text();

        expect(text).toContain('Movies each month');
        expect(text).toContain('2025, movies. Most: June 2025 (13).');
    });

    it('lays a long period out as years by months', () => {
        const wrapper = mountSection({
            activity: activity({
                bucket: 'year',
                buckets: ['2019', '2020'],
                series: [{ type: 'movie', counts: [4, 6] }],
                days: null,
                months: { '2019-03': 4, '2020-11': 6 },
            }),
            period: { kind: 'all', year: null, from: '2019-03-02', to: '2020-12-01', days: 640 },
        });

        expect(wrapper.text()).toContain('Completed each year, per type');
        expect(wrapper.findComponent(MonthGrid).props('years')).toEqual([2019, 2020]);

        // Screen readers get the grid as a table: a row a year, a cell a month.
        const rows = wrapper.findComponent(MonthGrid).findAll('tbody tr');
        expect(rows.map((row) => row.find('th').text())).toEqual(['2019', '2020']);
        expect(rows[0]?.findAll('td')[2]?.text()).toBe('4');
        expect(rows[1]?.findAll('td')[10]?.text()).toBe('6');
    });
});
