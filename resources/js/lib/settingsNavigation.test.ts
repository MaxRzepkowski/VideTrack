import { settingsNav } from '@/lib/settingsNavigation';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

describe('the settings menu', () => {
    it('lists connected accounts only where a provider is configured, and the password only where it signs in', () => {
        const ids = () => settingsNav().map((entry) => entry.id);

        expect(ids()).toContain('settings.password');
        expect(ids()).not.toContain('settings.identities');

        setPageProps({ passwordLogin: false, identityProviders: [{ code: 'oidc', label: 'Home SSO', glyph: 'openid' }] });

        expect(ids()).not.toContain('settings.password');
        expect(ids()).toContain('settings.identities');
    });

    it('gives an admin the Instance page where everyone else has their tasks', () => {
        const ids = () => settingsNav().map((entry) => entry.id);

        expect(ids()).toContain('settings.tasks');
        expect(ids()).not.toContain('settings.instance');

        setPageProps({ auth: { user: { id: 1, name: 'Alex', username: 'alex', is_admin: true } } });

        expect(ids()).not.toContain('settings.tasks');
        expect(ids()).toContain('settings.instance');
    });
});
