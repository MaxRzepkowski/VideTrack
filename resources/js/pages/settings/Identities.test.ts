import Identities from '@/pages/settings/Identities.vue';
import { setPageProps } from '@/test/page';
import type { LinkedIdentity } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

function page(identities: LinkedIdentity[] = []) {
    setPageProps({
        identityProviders: [
            { code: 'oidc', label: 'Home SSO', glyph: 'openid' },
            { code: 'github', label: 'GitHub', glyph: 'github' },
        ],
    });

    return mount(Identities, {
        props: { identities },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const connected: LinkedIdentity = { id: 7, provider: 'github', email: 'alex@example.com' };

describe('the connected accounts page', () => {
    it('lists every provider, connected or not', () => {
        const [sso, github] = page([connected]).findAll('li');

        expect(sso?.text()).toContain('Not connected');
        expect(sso?.find('a').text()).toBe('Connect');
        expect(github?.find('p').text()).toBe('Connected as alex@example.com');
        expect(github?.find('button').text()).toBe('Disconnect');
    });

    it('connects through a plain link to the trip to the provider', () => {
        const link = page()
            .findAll('a')
            .find((anchor) => anchor.text() === 'Connect');

        expect(link?.attributes('href')).toBe('/identities.redirect/oidc');
    });

    it('disconnects once the user confirms', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined as never);
        const wrapper = page([connected]);

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Disconnect')
            ?.trigger('click');
        await flushPromises();

        expect(remove).toHaveBeenCalledWith('/settings.identities.destroy/7', expect.anything());
    });

    it('shows why a disconnect was refused', () => {
        setPageProps({ errors: { identity: 'This is your only way to log in, so it stays until you add another.' } });

        expect(page([connected]).text()).toContain('This is your only way to log in');
    });
});
