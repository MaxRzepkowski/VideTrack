import { deferredComponent, IDLE_FALLBACK_MS, whenIdle } from '@/lib/deferred';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('whenIdle', () => {
    it('waits for the browser to be idle', () => {
        const idle = vi.fn();
        vi.stubGlobal('requestIdleCallback', idle);
        const work = vi.fn();

        whenIdle(work);
        expect(work).not.toHaveBeenCalled();

        idle.mock.calls[0]![0]();
        expect(work).toHaveBeenCalledOnce();
    });

    it('waits a pause where the browser cannot say it is idle', () => {
        vi.useFakeTimers();
        vi.stubGlobal('requestIdleCallback', undefined);
        Reflect.deleteProperty(window, 'requestIdleCallback');
        const work = vi.fn();

        whenIdle(work);
        vi.advanceTimersByTime(IDLE_FALLBACK_MS - 1);
        expect(work).not.toHaveBeenCalled();
        vi.advanceTimersByTime(1);
        expect(work).toHaveBeenCalledOnce();
    });
});

describe('deferredComponent', () => {
    it('fetches the component ahead, while the browser idles', () => {
        const idle = vi.fn();
        vi.stubGlobal('requestIdleCallback', idle);
        const load = vi.fn(() => Promise.resolve({ default: defineComponent({ template: '<div />' }) }));

        deferredComponent(load);
        expect(load).not.toHaveBeenCalled();

        idle.mock.calls[0]![0]();
        expect(load).toHaveBeenCalledOnce();
    });
});
