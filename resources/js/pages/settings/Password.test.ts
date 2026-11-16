import Password from '@/pages/settings/Password.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

function page() {
    return mount(Password, {
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const button = (wrapper: ReturnType<typeof page>, text: string) => wrapper.findAll('button').find((candidate) => candidate.text() === text);

describe('Password', () => {
    it('has nothing to save until a field is filled, and puts it back on discard', async () => {
        const wrapper = page();

        expect(button(wrapper, 'Save')?.attributes('disabled')).toBeDefined();

        await wrapper.find('#current_password').setValue('old-secret');

        expect(wrapper.text()).toContain('You have unsaved changes.');
        expect(button(wrapper, 'Save')?.attributes('disabled')).toBeUndefined();

        await button(wrapper, 'Discard')?.trigger('click');

        expect((wrapper.find('#current_password').element as HTMLInputElement).value).toBe('');
        expect(button(wrapper, 'Save')?.attributes('disabled')).toBeDefined();
    });

    it('sends the password, empties the fields and says it saved', async () => {
        const success = vi.spyOn(toast, 'success').mockImplementation(() => 1);
        const put = vi
            .spyOn(router, 'put')
            .mockImplementation(((_url: string, _data: unknown, options: { onSuccess: () => void }) => options.onSuccess()) as never);
        const wrapper = page();

        await wrapper.find('#current_password').setValue('old-secret');
        await wrapper.find('#password').setValue('new-secret');
        await wrapper.find('#password_confirmation').setValue('new-secret');
        await button(wrapper, 'Save')?.trigger('click');
        await flushPromises();

        expect(put).toHaveBeenCalledWith(
            '/settings.password.update',
            { current_password: 'old-secret', password: 'new-secret', password_confirmation: 'new-secret' },
            expect.anything(),
        );
        expect(success).toHaveBeenCalledWith('Saved.');
        expect((wrapper.find('#password').element as HTMLInputElement).value).toBe('');
    });

    it('sets the first password of an account without one, with no current password asked', () => {
        setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences(), password_sign_in: false } } });
        const wrapper = page();

        expect(wrapper.text()).toContain('Set password');
        expect(wrapper.find('#current_password').exists()).toBe(false);
        expect(wrapper.find('#password').exists()).toBe(true);
    });
});
