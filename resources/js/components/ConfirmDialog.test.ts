import ConfirmDialog from '@/components/ConfirmDialog.vue';
import { confirm } from '@/composables/useConfirm';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const button = (label: string) =>
    [...document.querySelectorAll<HTMLButtonElement>('[role=dialog] button')].find((candidate) => candidate.textContent?.trim() === label)!;

describe('ConfirmDialog', () => {
    it('heads with the title and says what happens under it, and goes ahead on the action', async () => {
        const wrapper = mount(ConfirmDialog, { attachTo: document.body });
        const answer = confirm({ title: 'Remove progress?', message: 'The counter and the status are cleared.', confirmLabel: 'Remove progress' });
        await flushPromises();

        expect(document.querySelector('[role=dialog] h2')?.textContent).toBe('Remove progress?');
        expect(document.querySelector('[role=dialog]')?.textContent).toContain('The counter and the status are cleared.');

        button('Remove progress').click();

        expect(await answer).toBe(true);

        wrapper.unmount();
    });

    it('heads with the message when there is no title, and cancels', async () => {
        const wrapper = mount(ConfirmDialog, { attachTo: document.body });
        const answer = confirm({ message: 'Delete all notifications?', confirmLabel: 'Delete all' });
        await flushPromises();

        expect(document.querySelector('[role=dialog] h2')?.textContent).toBe('Delete all notifications?');

        button('Cancel').click();

        expect(await answer).toBe(false);

        wrapper.unmount();
    });
});
