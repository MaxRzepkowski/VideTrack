import Preferences from '@/pages/settings/Preferences.vue';
import { useMediaTypes, userPreferences } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { MatureContentOption } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

function page(timezone: string | null, matureContent: MatureContentOption[] = []) {
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
        props: { regions: { PL: 'Poland' }, defaultRegion: 'PL', matureContent },
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

    it('sets which image an episode shows on the calendar and on the countdowns, each on its own', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        vi.spyOn(Intl, 'supportedValuesOf').mockReturnValue(['Europe/Warsaw']);
        const wrapper = page(null);

        expect((wrapper.find('select#calendar_episode_image').element as HTMLSelectElement).value).toBe('backdrop');

        await wrapper.find('select#countdown_episode_image').setValue('still');
        await wrapper.find('form').trigger('submit');

        expect(visit.mock.lastCall?.[1]).toMatchObject({ data: { calendar_episode_image: 'backdrop', countdown_episode_image: 'still' } });
    });

    it('asks the reading pace of each type that is read, starting from the saved one', () => {
        useMediaTypes();
        const wrapper = page(null);

        expect((wrapper.find('input#reading_pace_pages').element as HTMLInputElement).value).toBe('50');
        expect(wrapper.find('input#reading_pace_chapters').exists()).toBe(true);
        expect(wrapper.find('input#reading_pace_issues').exists()).toBe(true);
        expect(wrapper.text()).toContain('pages per hour');
    });

    it('sets how far each provider goes, among the levels it tells apart, and sends them together', async () => {
        const visit = vi.spyOn(router, 'visit').mockImplementation(() => undefined);
        useMediaTypes();
        const wrapper = page(null, [
            { provider: 'films', types: ['movie', 'show'], levels: ['suggestive', 'adult'], level: 'suggestive' },
            { provider: 'comics', types: ['manga'], levels: ['safe', 'suggestive', 'adult'], level: 'safe' },
        ]);
        const levels = (id: string) =>
            wrapper
                .find(`select#${id}`)
                .findAll('option')
                .map((option) => option.attributes('value'));

        expect(wrapper.text()).toContain('Adult content');
        expect(levels('mature-films')).toEqual(['suggestive', 'adult']);
        expect(levels('mature-comics')).toEqual(['safe', 'suggestive', 'adult']);
        expect(wrapper.find('select#mature-comics').text()).toContain('Hide suggestive and adult titles');

        await wrapper.find('select#mature-films').setValue('adult');
        await wrapper.find('form').trigger('submit');

        expect(visit.mock.lastCall?.[1]).toMatchObject({ data: { mature_content: { films: 'adult', comics: 'safe' } } });
    });

    it('leaves the adult content card out where no provider files adult material', () => {
        expect(page(null).text()).not.toContain('Adult content');
    });
});
