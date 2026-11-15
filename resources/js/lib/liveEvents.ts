import { formatTime, formatTimestampDate } from '@/lib/format';
import type { EventEntry } from '@/types/generated';
import { trans } from 'laravel-vue-i18n';

/*
 * The "live" arithmetic: which events are on the air at a moment (every
 * place that marks one live asks here), and which the viewer put away from
 * the "live" button. The page hands over
 * the events starting within hours; the button switches itself on and off by
 * their times, never asking the server again.
 */

const DISMISSED_KEY = 'live-events-dismissed';

/** The countdowns' type option for events (the server's `EventCountdown::FILTER_TYPE`): no media type, a kind of its own. */
export const EVENT_FILTER_TYPE = 'event';

/** When an event runs, in the viewer's zone: its day, its start and its end. */
export function eventWhen(event: Pick<EventEntry, 'startsAt' | 'endsAt'>): string {
    return trans('calendar.event_when', {
        date: formatTimestampDate(event.startsAt) ?? '',
        start: formatTime(event.startsAt) ?? '',
        end: formatTime(event.endsAt) ?? '',
    });
}

/** The hours an event runs, in the viewer's zone: its start and its end. */
export function eventHours(event: Pick<EventEntry, 'startsAt' | 'endsAt'>): string {
    return trans('calendar.event_hours', { start: formatTime(event.startsAt) ?? '', end: formatTime(event.endsAt) ?? '' });
}

/** How an event is told apart in the viewer's dismissals. */
export function eventKey(event: Pick<EventEntry, 'provider' | 'ref'>): string {
    return `${event.provider}:${event.ref}`;
}

/** Whether an event is on the air at `now` (epoch ms): started, not yet over. */
export function isLive(event: Pick<EventEntry, 'startsAt' | 'endsAt'>, now: number): boolean {
    return Date.parse(event.startsAt) <= now && now < Date.parse(event.endsAt);
}

/** The events on the air at `now` (epoch ms). */
export function liveAt(events: EventEntry[], now: number): EventEntry[] {
    return events.filter((event) => isLive(event, now));
}

/** The events the viewer closed the button for; another event shows as usual. */
export function dismissedEvents(storage: Pick<Storage, 'getItem'> = window.localStorage): Set<string> {
    try {
        const stored: unknown = JSON.parse(storage.getItem(DISMISSED_KEY) ?? '[]');

        return new Set(Array.isArray(stored) ? stored.filter((key): key is string => typeof key === 'string') : []);
    } catch {
        return new Set();
    }
}

/**
 * Remembers a dismissal. Only the events still worth remembering are kept
 * (those the page still knows), so the list never grows past a handful.
 */
export function dismissEvent(event: EventEntry, known: EventEntry[], storage: Pick<Storage, 'getItem' | 'setItem'> = window.localStorage): void {
    const keep = new Set(known.map(eventKey));
    const dismissed = [...dismissedEvents(storage)].filter((key) => keep.has(key));

    storage.setItem(DISMISSED_KEY, JSON.stringify([...new Set([...dismissed, eventKey(event)])]));
}
