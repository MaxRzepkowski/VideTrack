import ConfirmAccountField from '@/components/ConfirmAccountField.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function field(passwordSignIn: boolean, errors: { password?: string; username?: string } = {}) {
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences(), password_sign_in: passwordSignIn } } });

    return mount(ConfirmAccountField, { props: { idPrefix: 'clear-data', errors, password: '', username: '' } });
}

describe('the field confirming what cannot be undone', () => {
    it('asks for the password where it signs in', () => {
        const wrapper = field(true, { password: 'The password is incorrect.' });

        expect(wrapper.find('#clear-data-password').attributes('type')).toBe('password');
        expect(wrapper.find('#clear-data-username').exists()).toBe(false);
        expect(wrapper.text()).toContain('The password is incorrect.');
    });

    it('asks for the username, named in its label, where no password signs in', async () => {
        const wrapper = field(false, { username: 'That is not your username.' });

        expect(wrapper.find('#clear-data-password').exists()).toBe(false);
        expect(wrapper.find('label[for="clear-data-username"]').text()).toBe('Type your username, alex, to confirm');
        expect(wrapper.text()).toContain('That is not your username.');

        await wrapper.find('#clear-data-username').setValue('alex');

        expect(wrapper.emitted('update:username')).toEqual([['alex']]);
    });
});
