import NotificationPanel from '@/components/notifications/NotificationPanel.vue';
import { notificationEntry } from '@/test/factories';
import type { NotificationEntry, NotificationFeed } from '@/types/notifications';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

const entry = (overrides: Partial<NotificationEntry> = {}) =>
    notificationEntry({ event: 'premiere', subject: 'Dune', label: 'Out today', facts: ['Movie release'], ...overrides });

function panel(feed: NotificationFeed) {
    return mount(NotificationPanel, { props: { feed, followingHref: '/following' }, global: { stubs: { Link } } });
}

describe('NotificationPanel', () => {
    it('lists the latest by day, each with what happened, and leads on to all of them and to what you follow', () => {
        const wrapper = panel({ unread: 1, recent: [entry(), entry({ id: 'n2', createdAt: '2026-10-09T09:00:00Z', subject: 'Arrival' })] });

        expect(wrapper.findAll('h3').map((heading) => heading.text())).toEqual(['Today', 'Yesterday']);
        expect(wrapper.text()).toContain('Out today');
        expect(wrapper.text()).toContain('Movie release');
        expect(wrapper.find('a[href="/notifications.index"]').text()).toBe('All notifications');
        expect(wrapper.find('a[href="/following"]').text()).toBe('Followed');
    });

    it('marks all read on request, offered only while something is unread', async () => {
        const wrapper = panel({ unread: 1, recent: [entry()] });
        const markAll = () => wrapper.findAll('button').find((button) => button.text() === 'Mark all as read');

        await markAll()!.trigger('click');

        expect(wrapper.emitted('markAllRead')).toHaveLength(1);
        expect(panel({ unread: 0, recent: [entry({ readAt: '2026-10-10T10:00:00Z' })] }).text()).not.toContain('Mark all as read');
    });

    it('says what will show up when there is nothing yet, keeping only the way to what you follow', () => {
        const wrapper = panel({ unread: 0, recent: [] });

        expect(wrapper.text()).toContain('No notifications');
        expect(wrapper.text()).toContain('Release dates, status changes and premieres of titles you follow show up here.');
        expect(wrapper.find('a[href="/notifications.index"]').exists()).toBe(false);
        expect(wrapper.find('a[href="/following"]').exists()).toBe(true);
    });

    it('closes itself before a way out is taken', async () => {
        const wrapper = panel({ unread: 0, recent: [entry({ readAt: '2026-10-10T10:00:00Z' })] });

        await wrapper.find('a[href="/following"]').trigger('click');

        expect(wrapper.emitted('leave')).toHaveLength(1);
    });
});
