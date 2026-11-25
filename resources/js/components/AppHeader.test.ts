import AppHeader from '@/components/AppHeader.vue';
import type { JsonFetchOptions } from '@/lib/http';
import { setPageProps } from '@/test/page';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const calls: { url: string; method: string }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET' }: JsonFetchOptions = {}) => {
        calls.push({ url, method });

        return Promise.resolve(new Response(JSON.stringify({ unread: 0, recent: [] }), { status: 200 }));
    },
}));

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };
// The menus open in place, so what they hold is on the page to read.
const Inline = { template: '<div><slot /></div>' };

function header(unread: number) {
    setPageProps({ unreadNotifications: unread });

    return mount(AppHeader, {
        global: {
            stubs: {
                Link,
                MainMenu: true,
                MenuDrawer: true,
                UserMenuContent: true,
                DropdownMenu: Inline,
                DropdownMenuTrigger: Inline,
                DropdownMenuContent: Inline,
            },
        },
    });
}

beforeEach(() => {
    calls.length = 0;
});

describe('AppHeader', () => {
    it('leads both search controls, the box and the phone icon, to the search page', () => {
        const wrapper = header(0);
        const search = wrapper.findAll('a[href="/search"]');

        expect(search).toHaveLength(2);
        expect(search[0]!.text()).toContain('Search');
        expect(search[0]!.text()).toContain('Ctrl K');
        expect(search[1]!.attributes('aria-label')).toBe('Search');

        wrapper.unmount();
    });

    it('names how many are unread and marks them all read, then asks for the bell again', async () => {
        const wrapper = header(3);

        // The bell shows a dot; how many is in its name.
        expect(wrapper.find('[aria-label="Notifications, 3 unread"]').exists()).toBe(true);

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Mark all as read')!
            .trigger('click');
        await flushPromises();

        expect(calls).toEqual([
            { url: '/ui.notifications.read-all', method: 'POST' },
            { url: '/notifications.feed', method: 'GET' },
        ]);

        wrapper.unmount();
    });

    it('offers nothing to mark when everything is read', () => {
        const wrapper = header(0);

        expect(wrapper.text()).not.toContain('Mark all as read');

        wrapper.unmount();
    });
});
