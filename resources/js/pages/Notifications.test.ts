import { vTooltip } from '@/directives/tooltip';
import Notifications from '@/pages/Notifications.vue';
import type { NotificationEntry } from '@/types/notifications';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

const sent: { url: string; method?: string }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, options: { method?: string } = {}) => {
        sent.push({ url, method: options.method });

        return Promise.resolve(new Response(null, { status: 204 }));
    },
}));

afterEach(() => {
    sent.length = 0;
});

function notification(overrides: Partial<NotificationEntry> = {}): NotificationEntry {
    return {
        id: 'n1',
        readAt: null,
        createdAt: '2026-10-10T12:00:00+00:00',
        event: 'episode_aired',
        title: 'New episode of Severance',
        detail: ['S02E05', 'The Return'],
        href: '/episode/1/2/5',
        imagePath: '/still.jpg',
        mediaTitle: 'The Return',
        data: {},
        ...overrides,
    };
}

function page(notifications: NotificationEntry[]) {
    return mount(Notifications, {
        props: { notifications, pagination: { currentPage: 1, totalPages: 1, total: notifications.length, perPage: 20 } },
        global: { directives: { tooltip: vTooltip }, stubs: { AppLayout: { template: '<div><slot /></div>' } } },
    });
}

describe('Notifications', () => {
    it('shows what the server wrote: the headline, its facts and the picture', () => {
        const wrapper = page([notification()]);

        expect(wrapper.text()).toContain('New episode of Severance');
        expect(wrapper.text()).toContain('S02E05');
        expect(wrapper.text()).toContain('The Return');
        expect(wrapper.find('img').attributes('alt')).toBe('The Return');
        expect(wrapper.find('img').attributes('src')).toContain('/still.jpg');
    });

    it('draws no picture for a notification without one', () => {
        expect(
            page([notification({ imagePath: null })])
                .find('img')
                .exists(),
        ).toBe(false);
    });

    it('opens a read notification where it leads', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

        await page([notification({ readAt: '2026-10-10T13:00:00+00:00' })])
            .find('a.line-clamp-2')
            .trigger('click');

        expect(visit).toHaveBeenCalledWith('/episode/1/2/5');
    });

    it('marks an unread one read before it leads there', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

        await page([notification()]).find('a.line-clamp-2').trigger('click');
        await flushPromises();

        expect(sent).toEqual([{ url: '/ui.notifications.read/n1', method: 'PATCH' }]);
        expect(visit).toHaveBeenCalledWith('/episode/1/2/5');
    });

    it('is a link a middle click opens in a new tab, marking it read and leaving this page where it is', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
        const link = page([notification()]).find('a.line-clamp-2');

        expect(link.attributes('href')).toBe('/episode/1/2/5');

        await link.trigger('auxclick', { button: 1 });
        await link.trigger('auxclick', { button: 2 });
        await link.trigger('click', { ctrlKey: true });
        await flushPromises();

        expect(visit).not.toHaveBeenCalled();
        expect(sent).toEqual([
            { url: '/ui.notifications.read/n1', method: 'PATCH' },
            { url: '/ui.notifications.read/n1', method: 'PATCH' },
        ]);
        expect(reload).toHaveBeenCalledWith({ only: ['notifications'] });
    });

    it('marks every notification read, and offers it only while one is unread', async () => {
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);

        expect(page([notification({ readAt: '2026-10-10T13:00:00+00:00' })]).text()).not.toContain('Mark all as read');

        await page([notification()])
            .findAll('button')
            .find((button) => button.text() === 'Mark all as read')
            ?.trigger('click');
        await flushPromises();

        expect(sent).toEqual([{ url: '/ui.notifications.read-all', method: 'POST' }]);
        expect(reload).toHaveBeenCalledWith({ only: ['notifications'] });
    });

    it('deletes one notification from its own button', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);

        await page([notification()]).find('button[aria-label="Delete notification"]').trigger('click');

        expect(remove).toHaveBeenCalledWith('/notifications.destroy/n1', expect.anything());
    });

    it('asks for another page of a long feed', async () => {
        const get = vi.spyOn(router, 'get').mockImplementation(() => undefined);
        const wrapper = mount(Notifications, {
            props: { notifications: [notification()], pagination: { currentPage: 1, totalPages: 3, total: 50, perPage: 20 } },
            global: { directives: { tooltip: vTooltip }, stubs: { AppLayout: { template: '<div><slot /></div>' } } },
        });

        await wrapper.find('button[aria-label="Next"]').trigger('click');

        expect(get).toHaveBeenCalledWith('/notifications.index', { page: 2 }, expect.anything());
    });
});
