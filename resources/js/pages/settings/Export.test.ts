import Export from '@/pages/settings/Export.vue';
import type { ExportShelf } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

type Format = { code: string; label: string; shelf: ExportShelf };

const formats: Format[] = [
    { code: 'videtrack', label: 'Download JSON', shelf: 'backup' },
    { code: 'history', label: 'History', shelf: 'spreadsheet' },
    { code: 'lists', label: 'Lists', shelf: 'spreadsheet' },
    { code: 'letterboxd', label: 'Letterboxd', shelf: 'service' },
];

function page(offered: Format[]) {
    return mount(Export, {
        props: { calendarFeedUrl: 'https://example.com/feed.ics', calendarWebcalUrl: 'webcal://example.com/feed.ics', formats: offered },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } } },
    });
}

const downloads = (wrapper: ReturnType<typeof page>) =>
    wrapper
        .findAll('a')
        .map((link) => [link.text(), link.attributes('href')])
        .filter(([, href]) => href?.startsWith('/settings.export.download'));

describe('Export', () => {
    it('offers every format the server registered, each on its shelf and in its order', () => {
        const wrapper = page(formats);

        expect(downloads(wrapper)).toEqual([
            ['Download JSON', '/settings.export.download/videtrack'],
            ['History', '/settings.export.download/history'],
            ['Lists', '/settings.export.download/lists'],
            ['Letterboxd', '/settings.export.download/letterboxd'],
        ]);
        expect(wrapper.text()).toContain('Other services');
    });

    it('leaves out a shelf with nothing on it', () => {
        const wrapper = page(formats.filter((format) => format.shelf !== 'service'));

        expect(wrapper.text()).not.toContain('Other services');
        expect(downloads(wrapper)).toHaveLength(3);
    });
});
