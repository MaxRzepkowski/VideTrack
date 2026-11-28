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
            attention: [
                {
                    level: 'problem',
                    title: 'The scheduler is not running',
                    details: [
                        { text: 'Nothing refreshes and no notifications or digests go out.', moments: {} },
                        { text: 'Restart the container.', moments: {} },
                    ],
                    since: '2026-10-10T11:00:00+00:00',
                },
            ],
            checkedAt: null,
        }).text();

        expect(text).toContain('The scheduler is not running');
        expect(text).toContain('Nothing refreshes and no notifications or digests go out. Restart the container.');
        expect(text).toContain('since 11:00 AM');
    });

    it('writes a moment in a detail and since when of another day with its date', () => {
        const text = page({
            attention: [
                {
                    level: 'warning',
                    title: 'TMDB refuses the key',
                    details: [{ text: 'Answered 401 at :time.', moments: { time: '2026-10-08T10:30:00Z' } }],
                    since: '2026-10-08T10:30:00+00:00',
                },
            ],
            checkedAt: null,
        }).text();

        expect(text).toContain('Answered 401 at Oct 8, 2026, 10:30 AM.');
        expect(text).toContain('since Oct 8, 2026, 10:30 AM');
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
