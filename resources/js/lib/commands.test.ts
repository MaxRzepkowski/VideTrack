import { allCommands, matchCommands } from '@/lib/commands';
import { router } from '@inertiajs/vue3';
import { loadLanguageAsync } from 'laravel-vue-i18n';
import { describe, expect, it, vi } from 'vitest';

describe('allCommands', () => {
    it('lists the menu, the app, your panels and settings, then logging out', () => {
        const ids = allCommands().map((command) => command.id);

        expect(ids[0]).toBe('nav.dashboard');
        expect(ids).toContain('nav.following');
        expect(ids).toContain('nav.settings.integrations');
        expect(ids.at(-1)).toBe('action.logout');
        expect(new Set(ids).size).toBe(ids.length);
    });

    it('tells your profile lists apart from the lists manager, and prefixes settings', () => {
        const label = (id: string) =>
            allCommands()
                .find((command) => command.id === id)
                ?.label();

        expect(label('nav.lists')).toBe('Lists');
        expect(label('nav.profile.lists')).toBe('Profile – Lists');
        expect(label('nav.settings.password')).toBe('Settings – Password');
    });

    it('goes to your own panel', () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);

        allCommands()
            .find((command) => command.id === 'nav.profile.ratings')
            ?.perform();

        expect(visit).toHaveBeenCalledWith('/users.ratings/alex');
    });
});

describe('matchCommands', () => {
    it('lists everything for an empty query', () => {
        expect(matchCommands(allCommands(), '  ')).toHaveLength(allCommands().length);
    });

    it('matches the label or a keyword, ignoring case', () => {
        const ids = (query: string) => matchCommands(allCommands(), query).map((command) => command.id);

        expect(ids('CALENDAR')).toContain('nav.calendar');
        expect(ids('premieres')).toContain('nav.calendar');
        expect(ids('sign out')).toEqual(['action.logout']);
        expect(ids('xyzzy')).toEqual([]);
    });

    it('matches the words the language shown lists, ignoring diacritics', async () => {
        const ids = (query: string) => matchCommands(allCommands(), query).map((command) => command.id);

        await loadLanguageAsync('pl');

        try {
            expect(ids('kalendarz')).toContain('nav.calendar');
            expect(ids('watchlist')).toContain('nav.lists');
            expect(ids('sledzenie')).toContain('nav.settings.tracking');
        } finally {
            await loadLanguageAsync('en');
        }
    });
});
