import ColumnChart from '@/components/statistics/ColumnChart.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const width = ref(0);

vi.mock('@vueuse/core', () => ({ useElementSize: () => ({ width, height: ref(0) }) }));

const hours = Array.from({ length: 24 }, (_, hour) => `${String(hour).padStart(2, '0')}:00`);

function chart() {
    return mount(ColumnChart, {
        props: {
            values: hours.map((_, hour) => hour),
            labels: hours,
            ticks: (hour: number) => hour % 3 === 0,
            color: 'var(--color-brand)',
            caption: 'By hour',
        },
        global: { directives: { tooltip: {} } },
    });
}

const axis = (wrapper: ReturnType<typeof chart>): string[] =>
    wrapper
        .findAll('.mt-2.flex > span')
        .map((label) => label.text())
        .filter((label) => label !== '');

describe('ColumnChart', () => {
    it('thins the labels under the axis on a narrow card', () => {
        width.value = 260;

        expect(axis(chart())).toEqual(['00:00', '06:00', '12:00', '18:00']);
    });

    it('labels every tick where there is room', () => {
        width.value = 1000;

        expect(axis(chart())).toHaveLength(8);
    });

    it('gives screen readers every value, whatever the axis shows', () => {
        width.value = 260;

        expect(chart().findAll('tbody tr')).toHaveLength(24);
    });
});
