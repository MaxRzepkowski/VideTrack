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
});
