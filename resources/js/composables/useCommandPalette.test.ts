import { useCommandPalette } from '@/composables/useCommandPalette';
import { withSetup } from '@/test/withSetup';
import { afterEach, describe, expect, it } from 'vitest';

function press(key: string, init: KeyboardEventInit = {}, target: EventTarget = window): KeyboardEvent {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init });
    target.dispatchEvent(event);

    return event;
}

afterEach(() => {
    const { result, wrapper } = withSetup(() => useCommandPalette());
    result.close();
    wrapper.unmount();
});

describe('useCommandPalette', () => {
    it('toggles on cmd+K or ctrl+K, even while typing', () => {
        const { result, wrapper } = withSetup(() => useCommandPalette());

        press('k', { ctrlKey: true });
        expect(result.open.value).toBe(true);
        press('K', { metaKey: true });
        expect(result.open.value).toBe(false);

        wrapper.unmount();
    });

    it('opens on a bare slash only where it would not be typed', () => {
        const { result, wrapper } = withSetup(() => useCommandPalette());
        const input = document.createElement('input');
        document.body.appendChild(input);

        press('/', {}, input);
        expect(result.open.value).toBe(false);

        const event = press('/');
        expect(result.open.value).toBe(true);
        expect(event.defaultPrevented).toBe(true);

        wrapper.unmount();
    });

    it('binds one listener however many layouts mount, and none once all are gone', () => {
        const first = withSetup(() => useCommandPalette());
        const second = withSetup(() => useCommandPalette());

        press('k', { ctrlKey: true });
        expect(first.result.open.value).toBe(true);

        first.wrapper.unmount();
        second.wrapper.unmount();
        press('k', { ctrlKey: true });
        expect(first.result.open.value).toBe(true);
    });
});
