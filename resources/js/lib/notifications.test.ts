import { clickTarget, markRead } from '@/lib/notifications';
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
