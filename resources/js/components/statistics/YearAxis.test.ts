import YearAxis from '@/components/statistics/YearAxis.vue';
import { setPageProps } from '@/test/page';
import type { PeriodSpan, YearCount } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => setPageProps({}));

const years: YearCount[] = Array.from({ length: 8 }, (_, index) => ({ year: 2019 + index, completions: 10 + index }));

const axis = (period: PeriodSpan) =>
    mount(YearAxis, {
        props: { years, allTime: 999, period, type: null },
        global: { stubs: { Link: { props: ['href'], template: '<a :href="href" v-bind="$attrs"><slot /></a>' } } },
    });

const shownYears = (wrapper: ReturnType<typeof axis>): string[] =>
    wrapper
        .findAll('a')
        .map((link) => link.text())
        .filter((text) => /\d{4}$/.test(text))
        .map((text) => text.slice(-4));

describe('YearAxis', () => {
    it('shows the years around the chosen one, lit, and pages back through the older ones', async () => {
        const wrapper = axis({ kind: 'year', year: 2026, from: '2026-01-01', to: '2026-12-31', days: 322 });

        // However many years the screen fits, the chosen one closes the row.
        const first = shownYears(wrapper);

        expect(first.at(-1)).toBe('2026');
        expect(first.length).toBeLessThan(years.length);
        expect(wrapper.find('[aria-current="page"]').text()).toContain('2026');

        await wrapper.find('button[aria-label="Earlier years"]').trigger('click');

        expect(shownYears(wrapper)[0]).toBe('2019');
        expect(shownYears(wrapper)).not.toContain('2026');
    });

    it('underlines the years a range of days touches, choosing none of them', () => {
        const wrapper = axis({ kind: 'range', year: null, from: '2025-11-01', to: '2026-01-31', days: 92 });

        const underlined = wrapper.findAll('.border-brand').map((year) => year.text());

        expect(underlined).toEqual(['2025', '2026']);
        expect(wrapper.find('[aria-current="page"]').exists()).toBe(false);
    });
});
