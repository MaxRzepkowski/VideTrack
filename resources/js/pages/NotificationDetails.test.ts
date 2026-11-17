import NotificationDetails from '@/pages/NotificationDetails.vue';
import { mediaSummary } from '@/test/factories';
import type { NotificationEntry } from '@/types/notifications';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const removed: NotificationEntry = {
    id: 'n1',
    readAt: null,
    createdAt: '2026-10-10T12:00:00+00:00',
    event: 'titles_removed',
    title: '2 titles from your library are no longer available at their data source',
    detail: ['Dune', 'Arrival'],
    href: '/notifications.show/n1',
    imagePath: null,
    mediaTitle: 'Dune',
    data: {},
};

function details(notification: NotificationEntry, available: boolean[]) {
    return mount(NotificationDetails, {
        props: {
            notification,
            items: available.map((isAvailable, index) => ({
                media: isAvailable ? mediaSummary({ ref: String(index) }) : null,
                available: isAvailable,
            })),
        },
        global: {
            stubs: { AppLayout: { template: '<div><slot /></div>' }, MediaGrid: { template: '<div class="grid"><slot /></div>' }, MediaCard: true },
        },
    });
}

describe('NotificationDetails', () => {
    it('heads the page with what the server wrote and counts the titles deleted since', () => {
        const wrapper = details(removed, [true, false]);

        expect(wrapper.find('h1').text()).toBe(removed.title);
        expect(wrapper.findAll('media-card-stub')).toHaveLength(1);
        expect(wrapper.text()).toContain('1 of them has been deleted');
    });

    it('leads to the archive only from titles gone from their source', () => {
        expect(details(removed, [true]).text()).toContain('All archived titles in your library');
        expect(details({ ...removed, event: 'collection_new_item' }, [true]).text()).not.toContain('All archived titles in your library');
    });

    it("shows an episode as the history does: its show's name under it, its code in the corner", () => {
        const episode = mediaSummary({ type: 'episode', ref: '10:1:2', number: 2, extra: { seasonNumber: 1, showTitle: 'Crystal Lake' } });
        const wrapper = mount(NotificationDetails, {
            props: { notification: { ...removed, event: 'episode_aired' }, items: [{ media: episode, available: true }] },
            global: {
                stubs: {
                    AppLayout: { template: '<div><slot /></div>' },
                    MediaGrid: { template: '<div class="grid"><slot /></div>' },
                    MediaCard: true,
                },
            },
        });
        const card = wrapper.findComponent({ name: 'MediaCard' });

        expect(card.props('subtitle')).toBe('Crystal Lake');
        expect(card.props('cornerBadge')).toBe('S01E02');
    });
});
