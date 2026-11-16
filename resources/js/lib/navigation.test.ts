import { MAIN_NAV, navHref, OTHER_PLACES, SETTINGS_NAV, settingsNav, USER_PANELS, userPanel } from '@/lib/navigation';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

const ALL = [...MAIN_NAV, ...OTHER_PLACES, ...USER_PANELS, ...SETTINGS_NAV];

describe('the navigation tree', () => {
    it('gives every place a label in words, an icon and keywords', () => {
        for (const entry of ALL) {
            expect(entry.label(), entry.id).not.toMatch(/^\w+\.\w+$/);
            expect(entry.icon, entry.id).toBeTruthy();
            expect(entry.keywords(), entry.id).not.toMatch(/^\w+\.\w+$/);
        }
    });

    it('keeps ids unique within each surface', () => {
        for (const surface of [MAIN_NAV, OTHER_PLACES, USER_PANELS, SETTINGS_NAV]) {
            const ids = surface.map((entry) => entry.id);

            expect(new Set(ids).size).toBe(ids.length);
        }
    });

    it('addresses a place as a path, a user panel by whose it is', () => {
        expect(navHref(MAIN_NAV[0])).toBe('/dashboard');
        expect(navHref(userPanel('history'), 'alex')).toBe('/users.history/alex');
    });

    it('shows the following tab to the owner only', () => {
        expect(USER_PANELS.filter((panel) => panel.ownerOnly).map((panel) => panel.id)).toEqual(['following']);
    });

    it('gives the profile the rounded person and following the bell', () => {
        expect(userPanel('show').icon).toBe(SETTINGS_NAV[0].icon);
        expect(userPanel('following').icon).toBe(OTHER_PLACES.find((entry) => entry.id === 'notifications')?.icon);
    });

    it('lists connected accounts only where a provider is configured, and the password only where it signs in', () => {
        const ids = () => settingsNav().map((entry) => entry.id);

        expect(ids()).toContain('settings.password');
        expect(ids()).not.toContain('settings.identities');

        setPageProps({ passwordLogin: false, identityProviders: [{ code: 'oidc', label: 'Home SSO', glyph: 'openid' }] });

        expect(ids()).not.toContain('settings.password');
        expect(ids()).toContain('settings.identities');
    });
});
