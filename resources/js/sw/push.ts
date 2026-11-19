/// <reference lib="webworker" />

import { pageToOpen, readPushMessage } from '@/lib/push';

declare const self: ServiceWorkerGlobalScope;

/**
 * Shows each push the instance sends, and opens its page on a click: in a tab
 * of the app already open when there is one, else in a new one.
 */
export function listenForPushes(): void {
    self.addEventListener('push', (event) => {
        const message = readPushMessage(event.data?.json());

        if (message === null) {
            return;
        }

        // When it was written, not when the device came online: not in TypeScript's options yet, but shown.
        const options: NotificationOptions & { timestamp: number } = {
            body: message.body ?? undefined,
            icon: message.icon,
            data: { url: message.url },
            timestamp: message.timestamp,
        };

        event.waitUntil(self.registration.showNotification(message.title, options));
    });

    self.addEventListener('notificationclick', (event) => {
        event.notification.close();
        const url = (event.notification.data as { url?: unknown } | null)?.url;

        if (typeof url === 'string') {
            event.waitUntil(openPage(url));
        }
    });
}

async function openPage(url: string): Promise<void> {
    // Only a tab this worker controls can be sent elsewhere.
    const windows = await self.clients.matchAll({ type: 'window' });
    const { href, tab } = pageToOpen(url, self.location.origin, windows);

    if (tab === null) {
        await self.clients.openWindow(href);

        return;
    }

    await (await tab.focus()).navigate(href);
}
