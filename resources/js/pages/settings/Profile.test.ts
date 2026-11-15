import Profile from '@/pages/settings/Profile.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function page() {
    return mount(Profile, {
        props: { languages: [{ value: 'en', label: 'English' }] },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const button = (wrapper: ReturnType<typeof page>, text: string) => wrapper.findAll('button').find((candidate) => candidate.text() === text);

describe('Profile', () => {
    it('keeps a picked avatar waiting for the save, and lets discard take it back', async () => {
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
                },
            },
        });
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
        expect(wrapper.text()).not.toContain('You have unsaved changes.');
        expect(button(wrapper, 'Save')?.attributes('disabled')).toBeDefined();
    });
});
