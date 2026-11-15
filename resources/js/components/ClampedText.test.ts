import ClampedText from '@/components/ClampedText.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

// The resize the browser would report, fired by the test.
let resized: () => void = () => undefined;

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            constructor(callback: () => void) {
                resized = callback;
            }

            observe(): void {}

            disconnect(): void {}
        },
    );
});

afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

function overflowing(scrollHeight: number): void {
    vi.spyOn(HTMLElement.prototype, 'scrollHeight', 'get').mockReturnValue(scrollHeight);
    vi.spyOn(HTMLElement.prototype, 'clientHeight', 'get').mockReturnValue(100);
}

describe('ClampedText', () => {
    it('offers no toggle for a text that fits the clamp', async () => {
        overflowing(100);
        const wrapper = mount(ClampedText, { props: { text: 'Short.' } });
        await flushPromises();

        expect(wrapper.find('button').exists()).toBe(false);
        expect(wrapper.find('p').classes()).toContain('line-clamp-6');
    });

    it('offers the toggle once a narrower window clamps the text, and drops it when it fits again', async () => {
        overflowing(100);
        const wrapper = mount(ClampedText, { props: { text: 'Grows long.' } });
        await flushPromises();

        overflowing(300);
        resized();
        await flushPromises();
        expect(wrapper.find('button').exists()).toBe(true);

        overflowing(100);
        resized();
        await flushPromises();
        expect(wrapper.find('button').exists()).toBe(false);
    });

    it('opens a text that overflows and closes it again', async () => {
        overflowing(300);
        const wrapper = mount(ClampedText, { props: { text: 'Long.' } });
        await flushPromises();

        await wrapper.find('button').trigger('click');
        expect(wrapper.find('p').classes()).not.toContain('line-clamp-6');
        expect(wrapper.find('button').text()).toBe('Show less');

        await wrapper.find('button').trigger('click');
        expect(wrapper.find('p').classes()).toContain('line-clamp-6');
    });

    it('clamps to as many lines as it is told', async () => {
        overflowing(300);
        const wrapper = mount(ClampedText, { props: { text: 'A long storyline.', lines: 4 } });
        await flushPromises();

        expect(wrapper.find('p').classes()).toContain('line-clamp-4');
        expect(wrapper.find('button').exists()).toBe(true);
    });
});
