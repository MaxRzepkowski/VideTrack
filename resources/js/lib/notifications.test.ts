import { clickTarget, eventLook, markRead, notificationAge, notificationDays } from '@/lib/notifications';
import { notificationEntry } from '@/test/factories';
import { describe, expect, it, vi } from 'vitest';

const sent: { url: string; method: string }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET' }: { method?: string } = {}) => {
        sent.push({ url, method });

        return Promise.resolve(new Response(null, { status: 204 }));
    },
}));

const click = (init: MouseEventInit, type = 'click') => new MouseEvent(type, init);

describe('clickTarget', () => {
    it('opens a plain click here, a modified or middle one elsewhere, the right button nowhere', () => {
        expect(clickTarget(click({ button: 0 }))).toBe('here');
        expect(clickTarget(click({ button: 0, ctrlKey: true }))).toBe('elsewhere');
        expect(clickTarget(click({ button: 0, metaKey: true }))).toBe('elsewhere');
        expect(clickTarget(click({ button: 1 }, 'auxclick'))).toBe('elsewhere');
        expect(clickTarget(click({ button: 2 }, 'auxclick'))).toBe('none');
    });
});

describe('markRead', () => {
    it('marks an unread notification read and leaves a read one alone', async () => {
        await markRead({ id: 'n1', readAt: null });
        await markRead({ id: 'n2', readAt: '2026-10-10T12:00:00Z' });

        expect(sent).toEqual([{ url: '/ui.notifications.read/n1', method: 'PATCH' }]);
    });
});

const entry = (id: string, createdAt: string) => notificationEntry({ id, createdAt });

describe('notificationDays', () => {
    it('groups by the day they came, naming today and yesterday and dating the rest', () => {
        const days = notificationDays([
            entry('a', '2026-10-10T09:00:00Z'),
            entry('b', '2026-10-10T07:00:00Z'),
            entry('c', '2026-10-09T20:00:00Z'),
            entry('d', '2026-10-02T20:00:00Z'),
        ]);

        expect(days.map((day) => [day.heading, day.items.map((item) => item.id)])).toEqual([
            ['Today', ['a', 'b']],
            ['Yesterday', ['c']],
            ['October 2', ['d']],
        ]);
    });
});

describe('notificationAge', () => {
    it('counts today in minutes or hours, and dates an older one', () => {
        expect(notificationAge('2026-10-10T11:55:00Z')).toBe('5m');
        expect(notificationAge('2026-10-10T10:00:00Z')).toBe('2h');
        expect(notificationAge('2026-10-02T10:00:00Z')).toBe('Oct 2');
    });
});

describe('eventLook', () => {
    it('reads what is out now in green, a moved date in blue, and an unknown event with the bell', () => {
        expect(eventLook('episode_aired').labelClass).toBe('text-event-out');
        expect(eventLook('date_changed').iconClass).toBe('text-event-dated');
        expect(eventLook(null).iconClass).toBe('text-ink-secondary');
    });
});
