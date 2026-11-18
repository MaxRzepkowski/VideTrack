import { TEST_SENT_SHOWN_MS, useTestMessage } from '@/composables/useTestMessage';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

function withTest(address = '/settings/webhooks/3/test') {
    let state!: ReturnType<typeof useTestMessage>;
    mount(defineComponent({ setup: () => ((state = useTestMessage(() => address)), () => h('div')) }));

    return state;
}

describe('useTestMessage', () => {
    afterEach(() => {
        vi.restoreAllMocks();
        vi.useRealTimers();
    });

    it('says the test arrived for a moment', () => {
        vi.useFakeTimers();
        const post = vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onSuccess?.({} as never));
        const test = withTest();

        test.send();

        expect(post).toHaveBeenCalledWith('/settings/webhooks/3/test', {}, expect.objectContaining({ preserveScroll: true }));
        expect(test.sent.value).toBe(true);

        vi.advanceTimersByTime(TEST_SENT_SHOWN_MS);

        expect(test.sent.value).toBe(false);
    });

    it('keeps why the test did not arrive, until the next one', () => {
        vi.spyOn(router, 'post').mockImplementation((_url, _data, options) => options?.onError?.({ test: 'The test message did not arrive.' }));
        const test = withTest();

        test.send();
        expect(test.error.value).toBe('The test message did not arrive.');

        vi.spyOn(router, 'post').mockImplementation(() => undefined);
        test.send();
        expect(test.error.value).toBeUndefined();
    });
});
