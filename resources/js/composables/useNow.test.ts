import { useNow } from '@/composables/useNow';
import { withSetup } from '@/test/withSetup';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

beforeEach(() => vi.useFakeTimers({ now: new Date('2026-10-10T12:00:00Z') }));
afterEach(() => vi.useRealTimers());

describe('useNow', () => {
    it('ticks once a second for every reader, from one shared clock', () => {
        const first = withSetup(() => useNow());
        const second = withSetup(() => useNow());

        vi.advanceTimersByTime(3000);

        expect(first.result.value).toBe(Date.parse('2026-10-10T12:00:03Z'));
        expect(second.result.value).toBe(first.result.value);
        expect(vi.getTimerCount()).toBe(1);

        first.wrapper.unmount();
        expect(vi.getTimerCount()).toBe(1);

        second.wrapper.unmount();
        expect(vi.getTimerCount()).toBe(0);
    });
});
