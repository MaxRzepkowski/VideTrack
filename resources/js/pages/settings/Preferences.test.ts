import Preferences from '@/pages/settings/Preferences.vue';
import { userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function page(timezone: string | null) {
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
                preferences: userPreferences({ timezone }),
            },
        },
    });

    return mount(Preferences, {
        props: { regions: { PL: 'Poland' }, defaultRegion: 'PL' },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const zoneValues = (wrapper: ReturnType<typeof page>) =>
    wrapper
        .find('select#timezone')
        .findAll('option')
        .map((option) => option.attributes('value'));

describe('Preferences', () => {
    it('keeps a saved zone the browser does not list, so saving again does not drop it', () => {
        vi.spyOn(Intl, 'supportedValuesOf').mockReturnValue(['Europe/Warsaw', 'America/New_York']);

        expect(zoneValues(page('UTC'))).toEqual(['', 'UTC', 'Europe/Warsaw', 'America/New_York']);
        expect(zoneValues(page('Europe/Warsaw'))).toEqual(['', 'Europe/Warsaw', 'America/New_York']);
    });

    it('offers each choice the server knows, by its own value', () => {
        const values = (id: string) =>
            page(null)
                .find(`select#${id}`)
                .findAll('option')
                .map((option) => option.attributes('value'));

        expect(values('time_format')).toEqual(['auto', '24', '12']);
        expect(values('week_start')).toEqual(['auto', 'monday', 'sunday']);
        expect(values('up-next-sort')).toEqual(['activity', 'remaining', 'released', 'title']);
    });
});
