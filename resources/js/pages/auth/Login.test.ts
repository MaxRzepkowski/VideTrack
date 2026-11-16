import Login from '@/pages/auth/Login.vue';
import { setPageProps } from '@/test/page';
import type { IdentityProviderOption } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const HOME_SSO: IdentityProviderOption = { code: 'oidc', label: 'Home SSO', glyph: 'openid' };

function page() {
    return mount(Login, { props: { canResetPassword: true }, global: { stubs: { Link: { template: '<a><slot /></a>' } } } });
}

const providerLinks = (wrapper: ReturnType<typeof page>) => wrapper.findAll('a').filter((link) => link.text().startsWith('Log in with'));

describe('the login page', () => {
    it('is the password form alone where no provider is configured', () => {
        const wrapper = page();

        expect(wrapper.find('#password').exists()).toBe(true);
        expect(providerLinks(wrapper)).toHaveLength(0);
        expect(wrapper.findAll('span.bg-line')).toHaveLength(0);
    });

    it('offers each provider below the form, as a plain link to the trip there', () => {
        setPageProps({ guest: true, identityProviders: [HOME_SSO, { code: 'github', label: 'GitHub', glyph: 'github' }] });
        const wrapper = page();
        const links = providerLinks(wrapper);

        expect(wrapper.find('#password').exists()).toBe(true);
        expect(links.map((link) => link.text())).toEqual(['Log in with Home SSO', 'Log in with GitHub']);
        expect(links[0]?.attributes('href')).toBe('/identities.redirect/oidc');
        expect(links[1]?.find('svg path').attributes('d')).toBeTruthy();
    });

    it('drops the password form where providers are the only way in', () => {
        setPageProps({ passwordLogin: false, identityProviders: [HOME_SSO] });
        const wrapper = page();

        expect(wrapper.find('#password').exists()).toBe(false);
        expect(wrapper.text()).toContain('Choose how to log in');
        expect(providerLinks(wrapper)).toHaveLength(1);
    });

    it('says why the last trip to a provider ended without signing in', () => {
        setPageProps({ identityProviders: [HOME_SSO], errors: { identity: 'The login was cancelled.' } });

        expect(page().text()).toContain('The login was cancelled.');
    });
});
