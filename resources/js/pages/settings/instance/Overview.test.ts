import Overview from '@/pages/settings/instance/Overview.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

function page(props: InstanceType<typeof Overview>['$props']) {
    return mount(Overview, {
        props,
        global: {
            stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } },
        },
    });
}

describe('Overview', () => {
    it('lists what needs the admin, with what to do', () => {
        const text = page({
            attention: [{ title: 'TMDB refuses the API key', detail: 'Put a new key in the .env file.', since: '2026-10-10T11:00:00+00:00' }],
            checkedAt: null,
        }).text();

        expect(text).toContain('TMDB refuses the API key');
        expect(text).toContain('Put a new key in the .env file.');
        expect(text).toContain('since');
    });

    it('reads an empty list as checked when the checks have looked', () => {
        expect(page({ attention: [], checkedAt: '2026-10-10T11:52:00+00:00' }).text()).toContain('Everything works, checked');
        expect(page({ attention: [], checkedAt: null }).text()).toContain('Nothing needs your attention.');
    });

    it('marks the tab it is on', () => {
        const current = page({ attention: [], checkedAt: null }).find('[aria-current="page"]');

        expect(current.text()).toBe('Overview');
    });
});
