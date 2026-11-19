import { usePushSubscription } from '@/composables/usePushSubscription';
import { fingerprintOf } from '@/lib/push';
import { withSetup } from '@/test/withSetup';
import { flushPromises } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

// The instance's key as base64url, and the same bytes.
const KEY = 'AQID';
const KEY_BYTES = [1, 2, 3];

function subscription(endpoint: string, key: number[] = KEY_BYTES): PushSubscription {
    return {
        endpoint,
        options: { applicationServerKey: new Uint8Array(key).buffer, userVisibleOnly: true },
        toJSON: () => ({ endpoint, keys: { p256dh: 'browser-key', auth: 'browser-auth' } }),
        unsubscribe: vi.fn(() => Promise.resolve(true)),
    } as unknown as PushSubscription;
}

/** A secure desktop browser with push, its worker installed, holding the given subscription. */
function browser(current: PushSubscription | null, answer: NotificationPermission = 'granted', made = subscription('https://push.example.com/new')) {
    const pushManager = {
        getSubscription: vi.fn(() => Promise.resolve(current)),
        subscribe: vi.fn(() => Promise.resolve(made)),
    };
    const registration = { pushManager };

    vi.stubGlobal('isSecureContext', true);
    vi.stubGlobal('PushManager', { supportedContentEncodings: ['aes128gcm'] });
    vi.stubGlobal('Notification', { permission: 'default', requestPermission: vi.fn(() => Promise.resolve(answer)) });
    Object.defineProperty(navigator, 'serviceWorker', {
        configurable: true,
        value: { getRegistration: () => Promise.resolve(registration), ready: Promise.resolve(registration) },
    });

    return pushManager;
}

afterEach(() => {
    vi.unstubAllGlobals();
    Reflect.deleteProperty(navigator, 'serviceWorker');
});

describe('usePushSubscription', () => {
    it('can switch push on, and knows the browser it runs in by its subscription', async () => {
        browser(subscription('https://push.example.com/here'));
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        const expected = await fingerprintOf('https://push.example.com/here');

        expect(result.support.value).toBe('ready');
        // Hashing runs outside the promise queue.
        await vi.waitFor(() => expect(result.fingerprint.value).toBe(expected));
    });

    it('says push cannot work without a service worker', async () => {
        browser(null);
        Object.defineProperty(navigator, 'serviceWorker', { configurable: true, value: { getRegistration: () => Promise.resolve(undefined) } });
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        expect(result.support.value).toBe('unsupported');
        expect(result.fingerprint.value).toBeNull();
    });

    it('subscribes once the user allows it', async () => {
        const pushManager = browser(null);
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        const made = await result.subscribe();

        expect(pushManager.subscribe).toHaveBeenCalledWith({ userVisibleOnly: true, applicationServerKey: new Uint8Array(KEY_BYTES) });
        expect(made).toEqual({
            body: { endpoint: 'https://push.example.com/new', public_key: 'browser-key', auth_token: 'browser-auth', content_encoding: 'aes128gcm' },
            replaced: null,
        });
        expect(result.fingerprint.value).toBe(await fingerprintOf('https://push.example.com/new'));
    });

    it('replaces a subscription made with an older key, and names it', async () => {
        const old = subscription('https://push.example.com/old', [9]);
        browser(old);
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        const made = await result.subscribe();

        expect(old.unsubscribe).toHaveBeenCalled();
        expect(made?.replaced).toBe('https://push.example.com/old');
    });

    it('subscribes nothing when the user says no, and says it is blocked', async () => {
        const pushManager = browser(null, 'denied');
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        expect(await result.subscribe()).toBeNull();
        expect(pushManager.subscribe).not.toHaveBeenCalled();
        expect(result.support.value).toBe('blocked');
    });

    it('stops listening in this browser', async () => {
        const here = subscription('https://push.example.com/here');
        browser(here);
        const { result } = withSetup(() => usePushSubscription(() => KEY));
        await flushPromises();

        await result.unsubscribe();

        expect(here.unsubscribe).toHaveBeenCalled();
        expect(result.fingerprint.value).toBeNull();
    });
});
