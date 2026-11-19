import { registerServiceWorker, removeServiceWorker, UPDATE_CHECK_INTERVAL_MS } from '@/lib/pwa';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

const workbox = vi.hoisted(() => ({
    listeners: new Map<string, () => void>(),
    register: vi.fn(),
    messageSkipWaiting: vi.fn(),
}));

vi.mock('workbox-window', () => ({
    Workbox: class {
        register = workbox.register;
        messageSkipWaiting = workbox.messageSkipWaiting;

        addEventListener(name: string, listener: () => void): void {
            workbox.listeners.set(name, listener);
        }
    },
}));

vi.mock('vue-sonner', () => ({ toast: vi.fn() }));

function fire(name: string): void {
    const listener = workbox.listeners.get(name);

    if (listener === undefined) {
        throw new Error(`Nothing listens to ${name}.`);
    }

    listener();
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    workbox.listeners.clear();
});

describe('registerServiceWorker', () => {
    it('offers a waiting version and reloads the tab once the user takes it', () => {
        vi.stubGlobal('navigator', { serviceWorker: {} });
        workbox.register.mockResolvedValue(undefined);
        const reload = vi.fn();
        vi.stubGlobal('location', { reload });

        registerServiceWorker();
        expect(workbox.register).toHaveBeenCalled();
        expect(toast).not.toHaveBeenCalled();

        fire('waiting');
        expect(toast).toHaveBeenCalledWith('A new version is available.', expect.objectContaining({ duration: Infinity }));

        const action = vi.mocked(toast).mock.calls[0]?.[1]?.action;
        expect(action).toMatchObject({ label: 'Reload' });
        (action as { onClick: () => void }).onClick();
        expect(workbox.messageSkipWaiting).toHaveBeenCalled();
        expect(reload).not.toHaveBeenCalled();

        fire('controlling');
        expect(reload).toHaveBeenCalled();
    });

    it('does not reload when the first install takes control', () => {
        vi.stubGlobal('navigator', { serviceWorker: {} });
        workbox.register.mockResolvedValue(undefined);

        registerServiceWorker();

        expect(workbox.listeners.has('controlling')).toBe(false);
    });

    it('asks the server for a new version on every interval', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('navigator', { serviceWorker: {} });
        const update = vi.fn().mockResolvedValue(undefined);
        workbox.register.mockResolvedValue({ update });

        registerServiceWorker();
        await vi.advanceTimersByTimeAsync(UPDATE_CHECK_INTERVAL_MS - 1);
        expect(update).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(1);
        expect(update).toHaveBeenCalledTimes(1);
    });

    it('stays out of a browser without service workers', () => {
        vi.stubGlobal('navigator', {});

        registerServiceWorker();

        expect(workbox.register).not.toHaveBeenCalled();
    });
});

describe('removeServiceWorker', () => {
    it('unregisters every worker the origin has', async () => {
        const unregister = vi.fn().mockResolvedValue(true);
        vi.stubGlobal('navigator', { serviceWorker: { getRegistrations: () => Promise.resolve([{ unregister }, { unregister }]) } });

        removeServiceWorker();
        await vi.waitFor(() => expect(unregister).toHaveBeenCalledTimes(2));
    });
});
