import DeleteUser from '@/components/DeleteUser.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

let mounted: VueWrapper | null = null;

// The dialog renders into the body, so each test takes its own away.
afterEach(() => {
    mounted?.unmount();
    mounted = null;
});

async function openDialog(passwordSignIn: boolean) {
    setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', preferences: userPreferences(), password_sign_in: passwordSignIn } } });
    mounted = mount(DeleteUser, { attachTo: document.body });

    await mounted.find('button').trigger('click');
}

describe('deleting the account', () => {
    it('is confirmed with the password where it signs in', async () => {
        await openDialog(true);

        expect(document.body.querySelector('#delete-account-password')).not.toBeNull();
        expect(document.body.querySelector('#delete-account-username')).toBeNull();
    });

    it('is confirmed by typing the username where no password signs in', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
        await openDialog(false);
        const field = document.body.querySelector<HTMLInputElement>('#delete-account-username');

        expect(document.body.textContent).toContain('Type your username, alex, to confirm');
        expect(document.body.querySelector('#delete-account-password')).toBeNull();

        field!.value = 'alex';
        field!.dispatchEvent(new Event('input'));
        await flushPromises();
        document.body.querySelector('form')!.dispatchEvent(new Event('submit'));

        expect(remove).toHaveBeenCalledWith('/settings.profile.destroy', expect.objectContaining({ data: { password: '', username: 'alex' } }));
    });
});
