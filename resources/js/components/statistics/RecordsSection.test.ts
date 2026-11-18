import RecordsSection from '@/components/statistics/RecordsSection.vue';
import { mediaSummary, useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { StatisticsRecords } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => {
    useMediaTypes();
    setPageProps({});
});

const week = [2, 1, 1, 0, 0, 0, 0].map((count, offset) => ({ day: `2025-03-0${3 + offset}`, count }));

const records: StatisticsRecords = {
    streak: { from: '2025-03-03', to: '2025-03-05', days: 3, shownFrom: '2025-02-17', shownTo: '2025-03-19', otherDays: ['2025-03-10'] },
    busiestDay: { day: { day: '2025-03-03', count: 2 }, week },
    marathon: {
        media: mediaSummary({ type: 'show', title: 'Harbour Lights', ref: '7', year: 2022 }),
        day: '2025-04-05',
        parts: 13,
        first: { season: 1, number: 1 },
        last: { season: 1, number: 13 },
        minutes: 312,
    },
    firstWeekday: 1,
};

const mountSection = (records: StatisticsRecords) =>
    mount(RecordsSection, {
        props: { records },
        global: {
            stubs: { Link: { props: ['href'], template: '<a :href="href"><slot /></a>' } },
            directives: { tooltip: {} },
        },
    });

describe('RecordsSection', () => {
    it('says each record with when it happened', () => {
        const text = mountSection(records).text();

        expect(text).toContain('3 days in a row');
        expect(text).toContain('March 3\u2009–\u20095, 2025');
        expect(text).toContain('2 completed');
        expect(text).toContain('Monday, March 3, 2025');
        expect(text).toContain('The week of March 3\u2009–\u20099, 2025, finished each day.');
        expect(text).toContain('13 episodes in one day');
        expect(text).toContain('S01E01–S01E13');
        expect(text).toContain('5h 12min');
    });

    it('leaves out a record the period does not have, and the section when it has none', () => {
        const some = mountSection({ ...records, streak: null, marathon: null });

        expect(some.text()).toContain('Busiest day');
        expect(some.text()).not.toContain('Longest run of days');
        expect(some.text()).not.toContain('Biggest marathon');

        const none = mountSection({ ...records, streak: null, busiestDay: null, marathon: null });

        expect(none.find('section').exists()).toBe(false);
    });
});
