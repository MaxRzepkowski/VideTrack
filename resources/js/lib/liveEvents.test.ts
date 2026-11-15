import { dismissedEvents, dismissEvent, eventHours, eventKey, eventWhen, isLive, liveAt } from '@/lib/liveEvents';
import type { EventEntry } from '@/types/generated';
import { describe, expect, it } from 'vitest';

const event = (ref: string, startsAt: string, endsAt: string): EventEntry => ({
    provider: 'igdb',
    ref,
    name: `Show ${ref}`,
    startsAt,
    endsAt,
    liveStreamUrl: 'https://www.youtube.com/watch?v=x',
    logoPath: null,
});

function memory(): Pick<Storage, 'getItem' | 'setItem'> {
    const items = new Map<string, string>();

    return { getItem: (key) => items.get(key) ?? null, setItem: (key, value) => void items.set(key, value) };
}

describe('liveAt', () => {
    const show = event('1', '2026-10-10T18:00:00Z', '2026-10-10T20:00:00Z');

    it('is on the air from its start to just before its end', () => {
        expect(liveAt([show], Date.parse('2026-10-10T17:59:59Z'))).toEqual([]);
        expect(liveAt([show], Date.parse('2026-10-10T18:00:00Z'))).toEqual([show]);
        expect(liveAt([show], Date.parse('2026-10-10T19:59:59Z'))).toEqual([show]);
        expect(liveAt([show], Date.parse('2026-10-10T20:00:00Z'))).toEqual([]);
    });
});

describe('dismissals', () => {
    it('remembers the events put away, forgetting those the page no longer knows', () => {
        const storage = memory();
        const today = event('1', '2026-10-10T18:00:00Z', '2026-10-10T20:00:00Z');
        const yesterday = event('2', '2026-10-09T18:00:00Z', '2026-10-09T20:00:00Z');

        dismissEvent(yesterday, [yesterday], storage);
        dismissEvent(today, [today], storage);

        expect([...dismissedEvents(storage)]).toEqual([eventKey(today)]);
    });

    it('reads a broken entry as nothing put away', () => {
        expect(dismissedEvents({ getItem: () => '{not json' }).size).toBe(0);
        expect(dismissedEvents({ getItem: () => '{"a":1}' }).size).toBe(0);
    });
});

describe('isLive and eventWhen', () => {
    // The clock is frozen at 2026-10-10T12:00Z.
    it('is live from the start up to, not at, the end', () => {
        expect(isLive({ startsAt: '2026-10-10T12:00:00Z', endsAt: '2026-10-10T13:00:00Z' }, Date.parse('2026-10-10T12:00:00Z'))).toBe(true);
        expect(isLive({ startsAt: '2026-10-10T11:00:00Z', endsAt: '2026-10-10T12:00:00Z' }, Date.parse('2026-10-10T12:00:00Z'))).toBe(false);
    });

    it('says the day and both hours', () => {
        expect(eventWhen({ startsAt: '2026-10-10T19:00:00Z', endsAt: '2026-10-10T21:00:00Z' })).toMatch(/2026.*–/);
    });

    it('says both hours without the day', () => {
        const hours = eventHours({ startsAt: '2026-10-10T19:00:00Z', endsAt: '2026-10-10T21:00:00Z' });

        expect(hours).toContain('–');
        expect(hours).not.toContain('2026');
    });
});
