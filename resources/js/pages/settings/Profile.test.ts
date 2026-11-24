import Profile from '@/pages/settings/Profile.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';

function page() {
    return mount(Profile, {
        props: { languages: [{ value: 'en', label: 'English' }] },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

function signedIn(passwordSignIn = true): void {
    setPageProps({
        auth: {
            user: {
                id: 1,
                name: 'Ada Example',
                username: 'ada',
                email: 'test@example.com',
                bio: null,
                is_private: false,
                locale: 'en',
                avatar: null,
                preferences: userPreferences(),
                password_sign_in: passwordSignIn,
            },
        },
    });
}

const button = (wrapper: ReturnType<typeof page>, text: string) => wrapper.findAll('button').find((candidate) => candidate.text() === text);

describe('Profile', () => {
    it('keeps a picked avatar waiting for the save, and lets discard take it back', async () => {
        signedIn();
        vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:avatar');
        vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
        const wrapper = page();
        const file = wrapper.find('input[type="file"]');

        Object.defineProperty(file.element, 'files', { value: [new File(['x'], 'me.png', { type: 'image/png' })] });
        await file.trigger('change');

        expect(wrapper.find('img').attributes('src')).toBe('blob:avatar');
        expect(wrapper.text()).toContain('You have unsaved changes.');

        await button(wrapper, 'Discard')?.trigger('click');

        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.text()).toContain('All changes saved');
        expect(button(wrapper, 'Save')?.attributes('disabled')).toBeDefined();
    });

    it('asks to confirm a new address, and stops asking once it is the saved one again', async () => {
        signedIn();
        const wrapper = page();
        const email = wrapper.find('input#email');

        expect(wrapper.text()).not.toContain('Confirm the new address.');

        await email.setValue('ada@example.com');

        expect(wrapper.text()).toContain('Confirm the new address.');
        await wrapper.find('input[type="password"]').setValue('secret');

        await email.setValue('TEST@example.com');

        expect(wrapper.text()).not.toContain('Confirm the new address.');
        await email.setValue('ada@example.com');
        expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('');
    });

    it('an account without a password confirms a new address with its username', async () => {
        signedIn(false);
        const wrapper = page();

        await wrapper.find('input#email').setValue('ada@example.com');

        expect(wrapper.find('input[type="password"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Type your username, ada, to confirm');
        expect(wrapper.find('input#email-change-username').exists()).toBe(true);
    });

    it('sends the confirmation with a new address, shows what the server refused, and empties it once saved', async () => {
        signedIn();
        const post = vi
            .spyOn(router, 'post')
            .mockImplementation(((_url: string, _data: unknown, options: { onSuccess: () => void }) => options.onSuccess()) as never);
        vi.spyOn(toast, 'success').mockImplementation(() => 1);
        const wrapper = page();

        await wrapper.find('input#email').setValue('ada@example.com');
        await wrapper.find('input[type="password"]').setValue('secret');
        await button(wrapper, 'Save')?.trigger('click');
        await flushPromises();

        expect(post).toHaveBeenCalledWith(
            '/settings.profile.update',
            expect.objectContaining({ email: 'ada@example.com', current_password: 'secret' }),
            expect.anything(),
        );
        expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('');
    });

    it('shows under the field why the server refused the confirmation', async () => {
        signedIn();
        vi.spyOn(router, 'post').mockImplementation(((_url: string, _data: unknown, options: { onError: (errors: Record<string, string>) => void }) =>
            options.onError({ current_password: 'The password is incorrect.' })) as never);
        const wrapper = page();

        await wrapper.find('input#email').setValue('ada@example.com');
        await button(wrapper, 'Save')?.trigger('click');
        await flushPromises();

        expect(wrapper.text()).toContain('The password is incorrect.');
    });
});
