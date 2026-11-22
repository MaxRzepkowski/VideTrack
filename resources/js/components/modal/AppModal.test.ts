import AppModal from '@/components/modal/AppModal.vue';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function modal(slots: Record<string, string>, size?: 'base' | 'confirm') {
    return mount(AppModal, { props: { open: true, title: 'Dune', ...(size ? { size } : {}) }, slots, attachTo: document.body });
}

const dialog = () => document.querySelector<HTMLElement>('[role=dialog]')!;

describe('AppModal', () => {
    it('heads with the title and its facts, which describe the dialog, over the body', async () => {
        const wrapper = modal({ meta: '<span>Watched</span>', default: '<p>Body</p>' });
        await flushPromises();

        expect(dialog().querySelector('h2')?.textContent).toBe('Dune');
        expect(dialog().getAttribute('aria-describedby')).toBe(dialog().querySelector('[id] > span')?.parentElement?.id);
        expect(dialog().textContent).toContain('Body');
        expect(dialog().className).toContain('md:w-110');

        wrapper.unmount();
    });

    it('has no description without facts, and is narrower as a confirmation', async () => {
        const wrapper = modal({ default: '<p>Body</p>' }, 'confirm');
        await flushPromises();

        expect(dialog().hasAttribute('aria-describedby')).toBe(false);
        expect(dialog().className).toContain('md:w-100');

        wrapper.unmount();
    });

    it('closes from its button', async () => {
        const wrapper = modal({ default: '<p>Body</p>' });
        await flushPromises();

        dialog().querySelector<HTMLButtonElement>('button[aria-label="Close"]')!.click();
        await flushPromises();

        expect(wrapper.emitted('update:open')).toEqual([[false]]);

        wrapper.unmount();
    });
});
