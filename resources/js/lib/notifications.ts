import { addDays } from '@/lib/calendar';
import { localDateKey } from '@/lib/format';
import { jsonFetch } from '@/lib/http';
import { pageLocale, viewerTimeZone } from '@/lib/pageContext';
import type { MediaEventType } from '@/types/generated';
import type { NotificationEntry } from '@/types/notifications';
import { Bell, CalendarClock, CalendarPlus, Play, RefreshCw, Trash2, type LucideIcon } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';

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

/** How a notification's event line looks: its glyph, and the colours of the glyph and the words beside it. */
export interface EventLook {
    icon: LucideIcon;
    iconClass: string;
    labelClass: string;
}

const OUT: EventLook = { icon: Play, iconClass: 'text-event-out', labelClass: 'text-event-out' };
const DATED: EventLook = { icon: CalendarClock, iconClass: 'text-event-dated', labelClass: 'text-ink-secondary' };
const ANNOUNCED: EventLook = { icon: CalendarPlus, iconClass: 'text-event-dated', labelClass: 'text-ink-secondary' };
const QUIET = { iconClass: 'text-ink-secondary', labelClass: 'text-ink-secondary' };

/**
 * The look of each kind of event: what is out now reads green, a date moved
 * or announced in the releases' blue, the rest quietly.
 */
const EVENT_LOOKS: Record<MediaEventType, EventLook> = {
    episode_aired: OUT,
    season_premiere: OUT,
    premiere: OUT,
    home_release: OUT,
    date_changed: DATED,
    season_announced: ANNOUNCED,
    related_announced: ANNOUNCED,
    person_new_title: ANNOUNCED,
    company_new_title: ANNOUNCED,
    collection_new_item: ANNOUNCED,
    show_status: { icon: RefreshCw, ...QUIET },
    titles_removed: { icon: Trash2, ...QUIET },
};

/** The look of an event's line; an event this version does not know wears the bell. */
export function eventLook(event: MediaEventType | null): EventLook {
    return event === null ? { icon: Bell, ...QUIET } : EVENT_LOOKS[event];
}

/** Notifications of one day, under the day's name. */
export interface NotificationDay {
    key: string;
    heading: string;
    items: NotificationEntry[];
}

/**
 * The notifications by the day they came, on the viewer's calendar, newest
 * day first as they arrive: "Today", "Yesterday", then the date.
 */
export function notificationDays(items: NotificationEntry[], now: Date = new Date()): NotificationDay[] {
    const today = localDateKey(now.toISOString());
    const yesterday = addDays(today, -1);
    const days: NotificationDay[] = [];

    for (const item of items) {
        const key = localDateKey(item.createdAt);
        const last = days[days.length - 1];

        if (last?.key === key) {
            last.items.push(item);

            continue;
        }

        const heading =
            key === today
                ? trans('common.today')
                : key === yesterday
                  ? trans('common.yesterday')
                  : new Intl.DateTimeFormat(pageLocale(), { month: 'long', day: 'numeric', timeZone: viewerTimeZone() }).format(
                        new Date(item.createdAt ?? now),
                    );

        days.push({ key, heading, items: [item] });
    }

    return days;
}

/** When a notification came, as short as its row has room for: hours or minutes ago today, else the day. */
export function notificationAge(createdAt: string, now: Date = new Date()): string {
    const at = new Date(createdAt);

    if (localDateKey(createdAt) !== localDateKey(now.toISOString())) {
        return new Intl.DateTimeFormat(pageLocale(), { month: 'short', day: 'numeric', timeZone: viewerTimeZone() }).format(at);
    }

    const minutes = Math.max(0, Math.floor((now.getTime() - at.getTime()) / 60_000));
    const [value, unit] = minutes < 60 ? [minutes, 'minute'] : [Math.floor(minutes / 60), 'hour'];

    return new Intl.NumberFormat(pageLocale(), { style: 'unit', unit, unitDisplay: 'narrow' }).format(value);
}
