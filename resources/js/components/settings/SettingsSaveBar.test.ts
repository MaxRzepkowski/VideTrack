import SettingsSaveBar from '@/components/settings/SettingsSaveBar.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const save = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('button').find((button) => button.text() === 'Save');

describe('SettingsSaveBar', () => {
    it("stays on a page's form, saying there is nothing to save until there is", async () => {
        const wrapper = mount(SettingsSaveBar, { props: { dirty: false, processing: false } });

        expect(wrapper.text()).toContain('All changes saved');
        expect(save(wrapper)?.attributes('disabled')).toBeDefined();

        await wrapper.setProps({ dirty: true });

        expect(wrapper.text()).toContain('You have unsaved changes.');
        expect(save(wrapper)?.attributes('disabled')).toBeUndefined();
    });

    it('shows on a card only while that card has something to save, as its two buttons, and passes the clicks on', async () => {
        const wrapper = mount(SettingsSaveBar, { props: { dirty: false, processing: false, placement: 'card' } });
        const status = () => wrapper.find('[role="status"]');

        // Said to a screen reader from a region already there; on screen the buttons say it.
        expect(wrapper.text()).toBe('');
        expect(status().classes()).toContain('sr-only');
        expect(wrapper.findAll('button')).toHaveLength(0);

        await wrapper.setProps({ dirty: true });

        expect(status().text()).toBe('You have unsaved changes.');
        expect(wrapper.find('.sticky').exists()).toBe(false);

        await save(wrapper)?.trigger('click');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Discard')
            ?.trigger('click');

        expect(wrapper.emitted('save')).toHaveLength(1);
        expect(wrapper.emitted('discard')).toHaveLength(1);
    });
});
