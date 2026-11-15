import { jsonFetch } from '@/lib/http';
import type { NotificationEntry } from '@/types/notifications';

/*
 * Opening a notification, the same in the bell and on its page: a real link
 * to its title, so a middle or Ctrl click opens it in a new tab as any link
 * does; an unread one is marked read on the way.
 */

/** Marks an unread notification read; a lost request only leaves it unread. */
export async function markRead(item: Pick<NotificationEntry, 'id' | 'readAt'>): Promise<void> {
    if (item.readAt !== null) {
        return;
    }

    await jsonFetch(route('ui.notifications.read', { notification: item.id }), { method: 'PATCH' }).catch(() => null);
}

/**
 * How a click on a notification's link goes: `here` is the page's own visit
 * (the caller marks it read first), `elsewhere` the browser's new tab or
 * window (the page stays, the mark goes along), `none` a click that opens
 * nothing (the right button's menu).
 */
export function clickTarget(event: MouseEvent): 'here' | 'elsewhere' | 'none' {
    if (event.type === 'auxclick') {
        return event.button === 1 ? 'elsewhere' : 'none';
    }

    return event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ? 'elsewhere' : 'here';
}
