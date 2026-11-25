import { useCommandPalette } from '@/composables/useCommandPalette';
import { useMediaModals } from '@/composables/useMediaModals';
import AppLayout from '@/layouts/AppLayout.vue';
import { mediaSummary } from '@/test/factories';
import { withSetup } from '@/test/withSetup';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

// Each deferred part as a marker, so what the test reads is whether it is on the page.
const { part } = vi.hoisted(() => ({
    part: async (name: string) => {
        const { h } = await import('vue');

        return { __esModule: true, default: { render: () => h('div', { 'data-part': name }) } };
    },
}));
vi.mock('@/components/media/WatchedModal.vue', () => part('watched'));
vi.mock('@/components/media/RateModal.vue', () => part('rate'));
vi.mock('@/components/media/ListsModal.vue', () => part('lists'));
vi.mock('@/components/media/CreatorListsModal.vue', () => part('creator-lists'));
vi.mock('@/components/command/CommandPalette.vue', () => part('palette'));
// The layout tells the server the browser's zone; nothing here to tell it to.
vi.mock('@/composables/useTimezoneSync', () => ({ useTimezoneSync: () => undefined }));

function layout() {
    return mount(AppLayout, {
        slots: { default: '<p>Page</p>' },
        global: {
            stubs: { AppHeader: true, AppFooter: true, ConfirmDialog: true, Toaster: true, LiveEventsButton: true, AppTooltip: true },
        },
    });
}

afterEach(() => {
    useMediaModals().close();
    // The palette's state is shared; its hooks want a component to hold them.
    const palette = withSetup(() => useCommandPalette());
    palette.result.close();
    palette.wrapper.unmount();
});

describe('AppLayout', () => {
    it('draws the page without any modal or the palette', () => {
        const wrapper = layout();

        expect(wrapper.text()).toContain('Page');
        expect(wrapper.find('[data-part]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('mounts a modal the first time it is asked for, and keeps it after it closes', async () => {
        const wrapper = layout();
        const modals = useMediaModals();

        modals.openWatched(mediaSummary());
        await nextTick();
        await flushPromises();
        expect(wrapper.find('[data-part="watched"]').exists()).toBe(true);
        expect(wrapper.find('[data-part="rate"]').exists()).toBe(false);

        modals.close();
        modals.openRate(mediaSummary());
        modals.openLists(mediaSummary());
        modals.openCreatorLists({ kind: 'company', provider: 'igdb', ref: '2' }, 'Studio');
        await nextTick();
        await flushPromises();

        expect(wrapper.findAll('[data-part]').map((part) => part.attributes('data-part'))).toEqual(['watched', 'rate', 'lists', 'creator-lists']);

        wrapper.unmount();
    });

    it("answers the palette's shortcut before the palette is here", async () => {
        const wrapper = layout();

        window.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', ctrlKey: true }));
        await nextTick();
        await flushPromises();

        expect(wrapper.find('[data-part="palette"]').exists()).toBe(true);

        wrapper.unmount();
    });
});
