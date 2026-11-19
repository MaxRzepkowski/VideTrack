import { jsonFetch } from '@/lib/http';
import {
    applicationServerKey,
    fingerprintOf,
    pageToOpen,
    pushSupport,
    readPushMessage,
    renewPushSubscription,
    subscribedWith,
    subscriptionBody,
    type PushEnvironment,
} from '@/lib/push';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));

// The instance's key as base64url, and the same bytes.
const KEY = 'AQID_-8';
const KEY_BYTES = [1, 2, 3, 255, 239];

const ready: PushEnvironment = { secure: true, pushApi: true, appleMobile: false, standalone: false, permission: 'default' };

function subscription(endpoint: string, key: number[] | null = KEY_BYTES): PushSubscription {
    return {
        endpoint,
        options: { applicationServerKey: key === null ? null : new Uint8Array(key).buffer, userVisibleOnly: true },
        toJSON: () => ({ endpoint, keys: { p256dh: 'browser-key', auth: 'browser-auth' } }),
        unsubscribe: vi.fn(() => Promise.resolve(true)),
    } as unknown as PushSubscription;
}

function answer(status: number, body: unknown = {}): Response {
    return new Response(JSON.stringify(body), { status });
}

afterEach(() => {
    vi.unstubAllGlobals();
    vi.mocked(jsonFetch).mockReset();
});

describe('pushSupport', () => {
    it('offers the button where push can ask', () => {
        expect(pushSupport(ready)).toBe('ready');
        expect(pushSupport({ ...ready, appleMobile: true, standalone: true })).toBe('ready');
    });

    it('names why push cannot be switched on', () => {
        expect(pushSupport({ ...ready, secure: false })).toBe('insecure');
        expect(pushSupport({ ...ready, appleMobile: true, pushApi: false })).toBe('install-first');
        expect(pushSupport({ ...ready, pushApi: false, permission: null })).toBe('unsupported');
        expect(pushSupport({ ...ready, permission: 'denied' })).toBe('blocked');
    });
});

describe('the subscription', () => {
    it('reads the instance key from base64url', () => {
        expect(Array.from(applicationServerKey(KEY))).toEqual(KEY_BYTES);
    });

    it('knows which key it was made with', () => {
        expect(subscribedWith(subscription('https://push.example.com/a'), KEY)).toBe(true);
        expect(subscribedWith(subscription('https://push.example.com/a', [9, 9]), KEY)).toBe(false);
        expect(subscribedWith(subscription('https://push.example.com/a', null), KEY)).toBe(false);
    });

    it('goes to the server with its keys, in the encoding the browser speaks', () => {
        vi.stubGlobal('PushManager', { supportedContentEncodings: ['aes128gcm', 'aesgcm'] });

        expect(subscriptionBody(subscription('https://push.example.com/a'))).toEqual({
            endpoint: 'https://push.example.com/a',
            public_key: 'browser-key',
            auth_token: 'browser-auth',
            content_encoding: 'aes128gcm',
        });

        vi.stubGlobal('PushManager', {});

        expect(subscriptionBody(subscription('https://push.example.com/a')).content_encoding).toBe('aesgcm');
    });

    it('is found on the list by the sha256 of its address', async () => {
        expect(await fingerprintOf('abc')).toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
    });
});

describe('readPushMessage', () => {
    it('takes what the server sends', () => {
        const message = { title: 'Dune', body: 'Out now', url: 'https://videtrack.test/m/1', icon: 'https://videtrack.test/i.png', timestamp: 1 };

        expect(readPushMessage(message)).toEqual(message);
        expect(readPushMessage({ ...message, body: null })?.body).toBeNull();
    });

    it('shows nothing it cannot read', () => {
        expect(readPushMessage(null)).toBeNull();
        expect(readPushMessage('Dune')).toBeNull();
        expect(readPushMessage({ title: 'Dune' })).toBeNull();
    });
});

describe('pageToOpen', () => {
    const tabs = [{ url: 'https://videtrack.test/calendar' }];

    it('sends an open tab of the app to the page', () => {
        expect(pageToOpen('https://videtrack.test/movie/1', 'https://videtrack.test', tabs)).toEqual({
            href: 'https://videtrack.test/movie/1',
            tab: tabs[0],
        });
    });

    it('opens a new tab when none is open, or the page lies on another address', () => {
        expect(pageToOpen('https://videtrack.test/movie/1', 'https://videtrack.test', [])).toEqual({
            href: 'https://videtrack.test/movie/1',
            tab: null,
        });
        expect(pageToOpen('http://videtrack.lan/movie/1', 'https://videtrack.test', tabs).tab).toBeNull();
    });
});

describe('renewPushSubscription', () => {
    function browser(current: PushSubscription | null, renewed: PushSubscription | Error) {
        vi.stubGlobal('PushManager', { supportedContentEncodings: ['aes128gcm'] });
        vi.stubGlobal('Notification', { permission: 'granted' });

        return {
            pushManager: {
                getSubscription: vi.fn(() => Promise.resolve(current)),
                subscribe: vi.fn(() => (renewed instanceof Error ? Promise.reject(renewed) : Promise.resolve(renewed))),
            },
        } as unknown as ServiceWorkerRegistration;
    }

    it('leaves a subscription made with the instance key alone', async () => {
        const registration = browser(subscription('https://push.example.com/a'), new Error('not asked'));
        vi.mocked(jsonFetch).mockResolvedValue(answer(200, { data: { publicKey: KEY } }));

        await renewPushSubscription(registration);

        expect(registration.pushManager.subscribe).not.toHaveBeenCalled();
    });

    it('subscribes again with a new key and hands the server the new subscription for the old', async () => {
        const old = subscription('https://push.example.com/old', [9]);
        const renewed = subscription('https://push.example.com/new');
        const registration = browser(old, renewed);
        vi.mocked(jsonFetch)
            .mockResolvedValueOnce(answer(200, { data: { publicKey: KEY } }))
            .mockResolvedValueOnce(new Response(null, { status: 204 }));

        await renewPushSubscription(registration);

        expect(old.unsubscribe).toHaveBeenCalled();
        expect(jsonFetch).toHaveBeenLastCalledWith('/settings.channels.devices.renew', {
            method: 'POST',
            body: expect.objectContaining({ endpoint: 'https://push.example.com/new', previous_endpoint: 'https://push.example.com/old' }),
        });
        expect(renewed.unsubscribe).not.toHaveBeenCalled();
    });

    it("drops the new subscription when the old one was not this account's", async () => {
        const renewed = subscription('https://push.example.com/new');
        const registration = browser(subscription('https://push.example.com/old', [9]), renewed);
        vi.mocked(jsonFetch)
            .mockResolvedValueOnce(answer(200, { data: { publicKey: KEY } }))
            .mockResolvedValueOnce(answer(404));

        await renewPushSubscription(registration);

        expect(renewed.unsubscribe).toHaveBeenCalled();
    });

    it('waits for a click where the browser asks for one', async () => {
        const registration = browser(subscription('https://push.example.com/old', [9]), new DOMException('A click is needed', 'NotAllowedError'));
        vi.mocked(jsonFetch).mockResolvedValue(answer(200, { data: { publicKey: KEY } }));

        await renewPushSubscription(registration);

        expect(jsonFetch).toHaveBeenCalledTimes(1);
    });

    it('asks nothing of a browser without a subscription, or signed out', async () => {
        const registration = browser(null, new Error('not asked'));

        await renewPushSubscription(registration);
        expect(jsonFetch).not.toHaveBeenCalled();

        const signedOut = browser(subscription('https://push.example.com/a', [9]), new Error('not asked'));
        vi.mocked(jsonFetch).mockResolvedValue(answer(401));

        await renewPushSubscription(signedOut);
        expect(signedOut.pushManager.subscribe).not.toHaveBeenCalled();
    });
});
