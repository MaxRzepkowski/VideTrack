import MainMenu from '@/components/header/MainMenu.vue';
import { testPage } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

function menu(url: string) {
    testPage.url = url;

    return mount(MainMenu, { attachTo: document.body, global: { stubs: { Link } } });
}

const word = (wrapper: ReturnType<typeof menu>, label: string) => wrapper.findAll('button').find((button) => button.text() === label)!;

describe('MainMenu', () => {
    it('shows Dashboard and the three group words, panels closed', () => {
        const wrapper = menu('/dashboard');

        expect(wrapper.text()).toContain('Dashboard');
        expect(wrapper.findAll('button').map((button) => button.text())).toEqual(['Library', 'Discover', 'Releases']);
        expect(wrapper.text()).not.toContain('Backlog');

        wrapper.unmount();
    });

    it('lights the word of the group the page belongs to, and only it', () => {
        const wrapper = menu('/users.history/alex');

        expect(word(wrapper, 'Library').classes()).toContain('bg-surface-raised');
        expect(word(wrapper, 'Discover').classes()).not.toContain('bg-surface-raised');
        expect(wrapper.find('a[href="/dashboard"]').classes()).not.toContain('bg-surface-raised');

        wrapper.unmount();
    });

    it('opens a group on click with its places, each saying what is there', async () => {
        const wrapper = menu('/dashboard');

        await word(wrapper, 'Releases').trigger('click');
        await flushPromises();

        const rows = wrapper.findAll('li li a');

        expect(rows.map((row) => row.attributes('href'))).toEqual(['/calendar', '/countdowns']);
        expect(rows[0]!.text()).toContain('Episodes and releases day by day');

        wrapper.unmount();
    });

    it("goes to a group's first place on a mouse click, and opens the panel on a key or a tap", async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => {});
        const wrapper = menu('/dashboard');

        word(wrapper, 'Library').element.dispatchEvent(new PointerEvent('click', { bubbles: true, pointerType: 'mouse' }));
        await flushPromises();

        expect(visit).toHaveBeenCalledWith('/users.history/alex');
        expect(wrapper.findAll('li li a')).toHaveLength(0);

        visit.mockClear();
        word(wrapper, 'Releases').element.dispatchEvent(new PointerEvent('click', { bubbles: true, pointerType: 'touch' }));
        await flushPromises();

        expect(visit).not.toHaveBeenCalled();
        expect(wrapper.findAll('li li a')).toHaveLength(2);

        visit.mockRestore();
        wrapper.unmount();
    });

    it('closes the panel once a place in it is chosen', async () => {
        const wrapper = menu('/dashboard');

        await word(wrapper, 'Library').trigger('click');
        await flushPromises();

        const backlog = wrapper.findAll('li li a').find((row) => row.text().startsWith('Backlog'))!;

        expect(backlog.attributes('href')).toBe('/lists.backlog');

        await backlog.trigger('click');
        await flushPromises();

        expect(wrapper.findAll('li li a')).toHaveLength(0);

        wrapper.unmount();
    });
});
