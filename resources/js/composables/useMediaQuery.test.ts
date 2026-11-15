import { useMediaQuery } from '@/composables/useMediaQuery';
import { withSetup } from '@/test/withSetup';
import { afterEach, describe, expect, it, vi } from 'vitest';

function fakeMediaList(matches: boolean) {
    const listeners = new Set<(event: MediaQueryListEvent) => void>();
    const list = {
        matches,
        addEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.add(listener),
        removeEventListener: (_: string, listener: (event: MediaQueryListEvent) => void) => listeners.delete(listener),
    };
    vi.stubGlobal('matchMedia', () => list);

    return { listeners, change: (to: boolean) => listeners.forEach((listener) => listener({ matches: to } as MediaQueryListEvent)) };
}

afterEach(() => vi.unstubAllGlobals());

describe('useMediaQuery', () => {
    it('starts from the current match and follows changes', () => {
        const media = fakeMediaList(false);
        const { result } = withSetup(() => useMediaQuery('(min-width: 1024px)'));

        expect(result.value).toBe(false);
        media.change(true);
        expect(result.value).toBe(true);
    });

    it('stops listening when the component goes', () => {
        const media = fakeMediaList(true);
        const { wrapper } = withSetup(() => useMediaQuery('(min-width: 1024px)'));

        wrapper.unmount();

        expect(media.listeners.size).toBe(0);
    });
});
