import ListStatistics from '@/components/lists/ListStatistics.vue';
import type { ListStatisticsData } from '@/types/media';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const stats = (overrides: Partial<ListStatisticsData> = {}): ListStatisticsData => ({
    itemCount: 10,
    types: [],
    trackableCount: 10,
    completedCount: 4,
    inProgressCount: 0,
    droppedCount: 0,
    upcomingCount: 0,
    totals: [],
    ...overrides,
});

function progress(data: ListStatisticsData): string | undefined {
    return mount(ListStatistics, { props: { stats: data }, global: { directives: { tooltip: {} } } })
        .findAll('dd')
        .at(-1)
        ?.text();
}

describe('the progress of a list', () => {
    it('runs over every position that can be finished', () => {
        expect(progress(stats())).toBe('40%');
    });

    it('leaves out what is not out yet', () => {
        expect(progress(stats({ upcomingCount: 2 }))).toBe('50%');
    });

    it('has no number while nothing is out', () => {
        expect(progress(stats({ completedCount: 0, upcomingCount: 10 }))).toBe('–');
    });
});
