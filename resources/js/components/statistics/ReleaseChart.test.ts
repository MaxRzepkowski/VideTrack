import ReleaseChart from '@/components/statistics/ReleaseChart.vue';
import { useMediaTypes } from '@/test/factories';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';

const width = ref(0);

vi.mock('@vueuse/core', () => ({ useElementSize: () => ({ width, height: ref(0) }) }));

beforeEach(() => {
    useMediaTypes();
});

function chart() {
    return mount(ReleaseChart, {
        props: { dots: [1990, 2000, 2010, 2020, 2024].map((year) => ({ year, type: 'book', rating: null })), type: 'book' },
        global: { directives: { tooltip: {} } },
    });
}

const axis = (wrapper: ReturnType<typeof chart>): string[] =>
    wrapper.findAll('span.whitespace-nowrap.-translate-x-1\\/2').map((label) => label.text());

describe('ReleaseChart', () => {
    it('keeps the newest year and drops a decade that would run into it', () => {
        width.value = 300;

        expect(axis(chart())).toEqual(['1990', '2000', '2010', '2024']);
    });

    it('names every decade where there is room', () => {
        width.value = 2000;

        expect(axis(chart())).toEqual(['1990', '2000', '2010', '2020', '2024']);
    });
});
