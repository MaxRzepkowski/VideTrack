import Tracking from '@/pages/settings/Tracking.vue';
import { testPage } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function page(url = '/settings/tracking') {
    testPage.url = url;

    return mount(Tracking, {
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const shown = (wrapper: ReturnType<typeof page>, name: string) =>
    (wrapper.get(`#tracking-panel-${name}`).element as HTMLElement).style.display !== 'none';

describe('Tracking', () => {
    it('opens on the calendar and countdowns, or on the tab its address names', () => {
        expect(shown(page(), 'views')).toBe(true);

        const linked = page('/settings/tracking?tab=notifications');

        expect(shown(linked, 'notifications')).toBe(true);
        expect(shown(linked, 'views')).toBe(false);
        expect(linked.get('#tracking-tab-notifications').attributes('aria-selected')).toBe('true');
        expect(shown(page('/settings/tracking?tab=elsewhere'), 'views')).toBe(true);
    });

    it('moves along the tabs with the arrow keys, the open one being the only stop', async () => {
        const wrapper = page();

        await wrapper.get('#tracking-tab-views').trigger('keydown', { key: 'ArrowLeft' });

        expect(shown(wrapper, 'creators')).toBe(true);
        expect(wrapper.get('#tracking-tab-creators').attributes('tabindex')).toBe('0');
        expect(wrapper.get('#tracking-tab-views').attributes('tabindex')).toBe('-1');

        await wrapper.get('#tracking-tab-creators').trigger('keydown', { key: 'Home' });

        expect(shown(wrapper, 'views')).toBe(true);
    });

    it('keeps the open tab in the address without a visit', async () => {
        const replace = vi.spyOn(window.history, 'replaceState');
        const wrapper = page();

        await wrapper.get('#tracking-tab-creators').trigger('click');

        expect(shown(wrapper, 'creators')).toBe(true);
        expect(String(replace.mock.lastCall?.[2])).toContain('tab=creators');
    });

    it('offers saving once a view option changes', async () => {
        const wrapper = page();

        expect(wrapper.text()).toContain('All changes saved');

        await wrapper.get('#countdown-release-mode').setValue('cinema');

        expect(wrapper.text()).toContain('You have unsaved changes.');
    });
});
