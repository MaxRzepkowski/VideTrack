import {
    activeMenuWord,
    currentMenuRow,
    DASHBOARD,
    groupItemHref,
    menuPlaces,
    NAV_GROUPS,
    navHref,
    OTHER_PLACES,
    SETTINGS_NAV,
    settingsNav,
    USER_PANELS,
    userPanel,
} from '@/lib/navigation';
import { setPageProps } from '@/test/page';
import { describe, expect, it } from 'vitest';

const ALL = [...menuPlaces(), ...OTHER_PLACES, ...USER_PANELS, ...SETTINGS_NAV];

describe('the navigation tree', () => {
    it('gives every place a label in words, an icon and keywords', () => {
        for (const entry of ALL) {
            expect(entry.label(), entry.id).not.toMatch(/^\w+\.\w+$/);
            expect(entry.icon, entry.id).toBeTruthy();
            expect(entry.keywords(), entry.id).not.toMatch(/^\w+\.\w+$/);
        }
    });

    it('keeps ids unique within each surface', () => {
        for (const surface of [menuPlaces(), OTHER_PLACES, USER_PANELS, SETTINGS_NAV]) {
            const ids = surface.map((entry) => entry.id);

            expect(new Set(ids).size).toBe(ids.length);
        }
    });

    it('addresses a place as a path, a user panel by whose it is', () => {
        expect(navHref(DASHBOARD)).toBe('/dashboard');
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

    it('puts each place of the main menu in one group, with a line saying what is there', () => {
        const rows = NAV_GROUPS.flatMap((group) => group.items);
        const ids = rows.map((item) => item.entry.id);

        expect(new Set(ids).size).toBe(ids.length);

        for (const item of rows) {
            expect(item.entry.description?.(), item.entry.id).toMatch(/\s/);
        }
    });

    it('addresses the library rows on the signed-in user and lands trending on its shelf', () => {
        const row = (id: string) => NAV_GROUPS.flatMap((group) => group.items).find((item) => item.entry.id === id)!;

        expect(groupItemHref(row('history'), 'alex')).toBe('/users.history/alex');
        expect(groupItemHref(row('backlog'), 'alex')).toBe('/lists.backlog');
        expect(groupItemHref(row('trending'), 'alex')).toBe('/discover#trending');
    });

    it('lights the word of the group the page belongs to', () => {
        expect(activeMenuWord('/dashboard', 'alex')).toBe('dashboard');
        expect(activeMenuWord('/users.history/alex?sort=plays', 'alex')).toBe('library');
        expect(activeMenuWord('/users.lists/alex/12', 'alex')).toBe('library');
        expect(activeMenuWord('/statistics', 'alex')).toBe('library');
        expect(activeMenuWord('/discover?type=movie', 'alex')).toBe('discover');
        expect(activeMenuWord('/suggestions.index/collections', 'alex')).toBe('discover');
        expect(activeMenuWord('/calendar?month=2026-11', 'alex')).toBe('releases');
    });

    it("lights nothing on a page outside the menu, someone else's profile among them", () => {
        expect(activeMenuWord('/users.history/sam', 'alex')).toBeNull();
        expect(activeMenuWord('/settings.profile.edit', 'alex')).toBeNull();
        expect(activeMenuWord('/dashboards', 'alex')).toBeNull();
    });

    it("lights the drawer row of the page, never the one leading to a section of another row's page", () => {
        expect(currentMenuRow('/dashboard', 'alex')).toBe('dashboard');
        expect(currentMenuRow('/users.history/alex', 'alex')).toBe('history');
        expect(currentMenuRow('/discover?type=game', 'alex')).toBe('discover');
        expect(currentMenuRow('/users.history/sam', 'alex')).toBeNull();
    });
});
