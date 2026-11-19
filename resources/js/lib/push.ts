import { jsonFetch } from '@/lib/http';
import type { WebPushMessage } from '@/types/generated';

/**
 * Web push in the browser: whether it can be switched on here, the
 * subscription as the server takes it, and the renewal a browser does by
 * itself once the instance's key pair changed.
 */

/** Whether push can be switched on in this browser, or why not. */
export type PushSupport =
    /** The button can ask. */
    | 'ready'
    /** Plain http outside localhost: browsers keep push from it. */
    | 'insecure'
    /** iPhone and iPad allow push only to an app added to the Home Screen. */
    | 'install-first'
    /** No Push API, or no service worker to receive it (the development server has none). */
    | 'unsupported'
    /** The user said no to this site in the browser; only its settings undo that. */
    | 'blocked';

export interface PushEnvironment {
    secure: boolean;
    pushApi: boolean;
    appleMobile: boolean;
    standalone: boolean;
    permission: NotificationPermission | null;
}

/** The encodings a server may encrypt to; aes128gcm is the standard, aesgcm what older browsers knew. */
export type PushContentEncoding = 'aes128gcm' | 'aesgcm';

/** What the server takes to send to a browser, as the settings routes read it. */
export interface PushSubscriptionBody {
    endpoint: string;
    public_key: string;
    auth_token: string;
    content_encoding: PushContentEncoding;
}

export function pushSupport(environment: PushEnvironment): PushSupport {
    if (!environment.secure) {
        return 'insecure';
    }

    if (environment.appleMobile && !environment.standalone) {
        return 'install-first';
    }

    if (!environment.pushApi) {
        return 'unsupported';
    }

    return environment.permission === 'denied' ? 'blocked' : 'ready';
}

export function currentPushEnvironment(): PushEnvironment {
    const pushApi = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    // An iPad asks for the desktop site and calls itself a Mac; a Mac has no touch screen.
    const appleMobile = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.userAgent.includes('Macintosh') && navigator.maxTouchPoints > 1);
    const standalone = window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone === true;

    return { secure: window.isSecureContext, pushApi, appleMobile, standalone, permission: pushApi ? Notification.permission : null };
}

/** The instance's public key as the Push API takes it: the bytes of its base64url text. */
export function applicationServerKey(publicKey: string): Uint8Array<ArrayBuffer> {
    const base64 = publicKey
        .replace(/-/g, '+')
        .replace(/_/g, '/')
        .padEnd(Math.ceil(publicKey.length / 4) * 4, '=');

    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

/** Whether the subscription was made with this key: one made with an older pair hears nothing. */
export function subscribedWith(subscription: PushSubscription, publicKey: string): boolean {
    const used = subscription.options.applicationServerKey;

    if (used === null) {
        return false;
    }

    const expected = applicationServerKey(publicKey);
    const actual = new Uint8Array(used);

    return actual.length === expected.length && actual.every((byte, index) => byte === expected[index]);
}

export function subscriptionBody(subscription: PushSubscription): PushSubscriptionBody {
    const { endpoint, keys } = subscription.toJSON();

    if (endpoint === undefined || keys?.p256dh === undefined || keys.auth === undefined) {
        throw new Error('A push subscription has an endpoint and its keys.');
    }

    const encodings = (PushManager as { supportedContentEncodings?: readonly string[] }).supportedContentEncodings ?? [];

    return {
        endpoint,
        public_key: keys.p256dh,
        auth_token: keys.auth,
        content_encoding: encodings.includes('aes128gcm') ? 'aes128gcm' : 'aesgcm',
    };
}

/** The hash the server lists a browser under: the page finds its own row by it. */
export async function fingerprintOf(endpoint: string): Promise<string> {
    const digest = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(endpoint));

    return Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
}

/** A push as the server sent it; null for anything else, which the service worker leaves unshown. */
export function readPushMessage(data: unknown): WebPushMessage | null {
    if (typeof data !== 'object' || data === null) {
        return null;
    }

    const { title, body, url, icon, timestamp } = data as Record<string, unknown>;

    if (typeof title !== 'string' || typeof url !== 'string' || typeof icon !== 'string' || typeof timestamp !== 'number') {
        return null;
    }

    return { title, body: typeof body === 'string' ? body : null, url, icon, timestamp };
}

/**
 * Where a click on a notification leads: a tab of the app already open, sent to the page, or a new one when none
 * is open or the page lies on another address than the worker's (APP_URL may name another host).
 */
export function pageToOpen<Tab extends { url: string }>(url: string, origin: string, tabs: readonly Tab[]): { href: string; tab: Tab | null } {
    const target = new URL(url, origin);
    const tab = target.origin === origin ? tabs.find((candidate) => new URL(candidate.url).origin === origin) : undefined;

    return { href: target.href, tab: tab ?? null };
}

/**
 * After the instance's key pair changed, a browser that already said yes
 * subscribes again by itself and hands the server its new subscription in
 * place of the old one. A browser that wants a click for it stays as it is:
 * the settings page offers "Turn on again". The new subscription is dropped
 * when the old one was another account's.
 */
export async function renewPushSubscription(registration: ServiceWorkerRegistration): Promise<void> {
    if (!('PushManager' in window) || Notification.permission !== 'granted') {
        return;
    }

    const current = await registration.pushManager.getSubscription();

    if (current === null) {
        return;
    }

    // Signed out, or an instance without push.
    const answer = await jsonFetch(route('settings.channels.devices.key'));

    if (!answer.ok) {
        return;
    }

    const { data } = (await answer.json()) as { data: { publicKey: string } };

    if (subscribedWith(current, data.publicKey)) {
        return;
    }

    const previous = current.endpoint;
    await current.unsubscribe();

    let renewed: PushSubscription;

    try {
        renewed = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: applicationServerKey(data.publicKey) });
    } catch (error) {
        // A browser that subscribes only on a click (NotAllowedError) waits for "Turn on again".
        if (error instanceof DOMException) {
            return;
        }

        throw error;
    }

    const renewal = await jsonFetch(route('settings.channels.devices.renew'), {
        method: 'POST',
        body: { ...subscriptionBody(renewed), previous_endpoint: previous },
    });

    if (!renewal.ok) {
        await renewed.unsubscribe();
    }
}
