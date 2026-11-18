import RangePanel from '@/components/statistics/RangePanel.vue';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';

beforeEach(() => setPageProps({}));

const panel = () => mount(RangePanel, { props: { from: null, to: null, earliest: '2016-01-01', whole: false }, attachTo: document.body });

const button = (text: string) => [...document.body.querySelectorAll('button')].find((element) => element.textContent?.trim() === text);

describe('RangePanel', () => {
    it('applies a ready-made range only when asked, and says how long it is', async () => {
        const wrapper = panel();

        await wrapper.find('button').trigger('click');

        const shortcut = [...document.body.querySelectorAll('button')].find((button) => button.textContent?.trim() === 'Last 30 days');
        shortcut?.click();
        await wrapper.vm.$nextTick();

        expect(document.body.textContent).toContain('30 days. First completion:');
        expect(wrapper.emitted('apply')).toBeUndefined();

        [...document.body.querySelectorAll('button')].find((button) => button.textContent?.trim() === 'Apply')?.click();
        await wrapper.vm.$nextTick();

        // The frozen clock: 2026-10-10.
        expect(wrapper.emitted('apply')?.[0]).toEqual(['2026-09-11', '2026-10-10']);

        wrapper.unmount();
    });

    it('applies the whole history as its own period, and a range again once its days are changed', async () => {
        const wrapper = panel();

        await wrapper.find('button').trigger('click');
        button('All time')?.click();
        await wrapper.vm.$nextTick();
        button('Apply')?.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('whole')).toHaveLength(1);
        expect(wrapper.emitted('apply')).toBeUndefined();

        await wrapper.find('button').trigger('click');
        button('All time')?.click();
        await wrapper.vm.$nextTick();
        const from = document.body.querySelector<HTMLInputElement>('input[type="date"]');
        if (from) {
            from.value = '2020-01-01';
            from.dispatchEvent(new Event('input'));
        }
        await wrapper.vm.$nextTick();
        button('Apply')?.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('apply')?.[0]).toEqual(['2020-01-01', '2026-10-10']);

        wrapper.unmount();
    });
});
