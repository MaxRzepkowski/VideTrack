import { jsonFetch } from '@/lib/http';
import Maintenance from '@/pages/settings/instance/Maintenance.vue';
import type { MaintenanceCheckEntry } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));

function check(overrides: Partial<MaintenanceCheckEntry> = {}): MaintenanceCheckEntry {
    return {
        code: 'provider-health',
        label: 'Metadata provider health',
        frequency: 'tasks.every_15_minutes',
        status: 'success',
        lines: [{ level: 'ok', line: { text: 'TMDB answers.', moments: {} }, details: [] }],
        error: null,
        startedAt: '2026-10-10T11:52:00+00:00',
        checkedAt: '2026-10-10T11:52:00+00:00',
        ...overrides,
    };
}

function page(checks: MaintenanceCheckEntry[]) {
    return mount(Maintenance, {
        props: { checks },
        global: {
            stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } },
        },
    });
}

describe('Maintenance', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.mocked(jsonFetch).mockReset();
    });

    it('shows how often a check runs and what its last run saw', () => {
        const text = page([check()]).text();

        expect(text).toContain('Metadata provider health');
        expect(text).toContain('Every 15 minutes');
        expect(text).toContain('TMDB answers.');
    });

    it('gives each provider its own line, in the colour of how it answers, with what to do', () => {
        const wrapper = page([
            check({
                lines: [
                    { level: 'ok', line: { text: 'TMDB answers.', moments: {} }, details: [] },
                    {
                        level: 'problem',
                        line: { text: "Hardcover refuses this instance's key.", moments: {} },
                        details: [
                            { text: 'Answered 401 invalid_token at :time.', moments: { time: '2026-10-10T11:30:00Z' } },
                            { text: 'Put a new key in HARDCOVER_API_KEY in the .env file.', moments: {} },
                        ],
                    },
                ],
            }),
        ]);

        expect(wrapper.find('span.text-danger').text()).toBe("Hardcover refuses this instance's key.");
        expect(wrapper.text()).toContain('Answered 401 invalid_token at 11:30 AM.');
        expect(wrapper.text()).toContain('Answered 401 invalid_token at 11:30 AM.');
        expect(wrapper.text()).toContain('Put a new key in HARDCOVER_API_KEY in the .env file.');
        expect(wrapper.findAll('svg.text-success')).toHaveLength(2);
    });

    it('says a check at work runs, and asks for the rows again until it ends', async () => {
        vi.mocked(jsonFetch).mockResolvedValue(
            new Response(JSON.stringify({ checks: [check({ lines: [{ level: 'ok', line: { text: 'Done again.', moments: {} }, details: [] }] })] })),
        );
        const wrapper = page([check({ status: 'started', lines: [] })]);

        expect(wrapper.text()).toContain('Running since');

        vi.advanceTimersByTime(3000);
        await flushPromises();

        expect(jsonFetch).toHaveBeenCalledWith('/settings.instance.checks.index');
        expect(wrapper.text()).toContain('Done again.');
    });

    it('leaves a finished list alone', () => {
        page([check()]);

        vi.advanceTimersByTime(9000);

        expect(jsonFetch).not.toHaveBeenCalled();
    });

    it('runs a check on request', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => {});
        const wrapper = page([check()]);

        await wrapper.find('button').trigger('click');

        expect(post).toHaveBeenCalledWith('/settings.instance.checks.run', { code: 'provider-health' }, expect.anything());
    });
});
