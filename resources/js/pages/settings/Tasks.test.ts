import { vTooltip } from '@/directives/tooltip';
import { jsonFetch } from '@/lib/http';
import Tasks from '@/pages/settings/Tasks.vue';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('@/lib/http', () => ({ jsonFetch: vi.fn() }));
vi.mock('@/composables/useConfirm', () => ({ confirm: vi.fn(() => Promise.resolve(true)) }));

type Run = {
    id: number;
    name: string;
    label: string;
    status: 'pending' | 'started' | 'success' | 'failure';
    summary: string | null;
    errors: string[];
    details: string[];
    queuedAt: string;
    finishedAt: string | null;
};

function run(overrides: Partial<Run> = {}): Run {
    return {
        id: 1,
        name: 'import-trakt',
        label: 'Import from Trakt.tv',
        status: 'success',
        summary: 'Processed 3 entries.',
        errors: [],
        details: [],
        queuedAt: '2026-10-10T11:00:00+00:00',
        finishedAt: '2026-10-10T11:01:00+00:00',
        ...overrides,
    };
}

type Entry = {
    name: string;
    label: string;
    frequency: string;
    nextRunAt: string;
    lastRunAt: string | null;
    lastStatus: Run['status'] | null;
};

const daily: Entry = {
    name: 'cleanup',
    label: 'Clean up expired data',
    frequency: 'common.daily',
    nextRunAt: '2026-10-11T03:30:00+00:00',
    lastRunAt: '2026-10-10T03:30:00+00:00',
    lastStatus: 'failure',
};

function page(runs: Run[], schedule: Entry[] = [], canClear = false) {
    return mount(Tasks, {
        props: { runs, schedule, canClear },
        global: {
            directives: { tooltip: vTooltip },
            stubs: { AppLayout: { template: '<div><slot /></div>' }, SettingsLayout: { template: '<div><slot /></div>' } },
        },
    });
}

describe('Tasks', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => {
        vi.useRealTimers();
        vi.mocked(jsonFetch).mockReset();
    });

    it('names a run as the server does and says a success with errors finished with errors', () => {
        const wrapper = page([run({ errors: ['A title was not found.'] })]);

        expect(wrapper.text()).toContain('Import from Trakt.tv');
        expect(wrapper.text()).toContain('Finished with errors');
    });

    it('asks for the runs again while one is under way, and shows what it gets', async () => {
        vi.mocked(jsonFetch).mockResolvedValue(new Response(JSON.stringify({ runs: [run({ summary: 'Processed 9 entries.' })], canClear: true })));
        const wrapper = page([run({ status: 'started', summary: 'Processing entry 2…' })]);

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();

        expect(jsonFetch).toHaveBeenCalledWith('/settings.tasks.runs');
        expect(wrapper.text()).toContain('Processed 9 entries.');
        expect(wrapper.text()).toContain('Clear finished runs');
    });

    it('asks once at a time, and drops an answer the page has moved past', async () => {
        let answer: (response: Response) => void = () => undefined;
        vi.mocked(jsonFetch).mockImplementation(() => new Promise<Response>((resolve) => (answer = resolve)));
        const wrapper = page([run({ status: 'started', summary: 'Processing entry 2…' })]);

        await vi.advanceTimersByTimeAsync(9000);
        expect(jsonFetch).toHaveBeenCalledTimes(1);

        // A visit (clearing, running a task) brings the page's own list first.
        await wrapper.setProps({ runs: [run({ status: 'started', summary: 'Queued again.' })], canClear: false });
        answer(new Response(JSON.stringify({ runs: [run({ summary: 'Stale.' })], canClear: true })));
        await flushPromises();

        expect(wrapper.text()).toContain('Queued again.');
        expect(wrapper.text()).not.toContain('Stale.');
    });

    it('lives through a poll that fails and asks again on the next tick', async () => {
        vi.mocked(jsonFetch).mockRejectedValueOnce(new TypeError('offline'));
        vi.mocked(jsonFetch).mockResolvedValue(new Response(JSON.stringify({ runs: [run({ summary: 'Back.' })], canClear: false })));
        const wrapper = page([run({ status: 'started' })]);

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();
        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();

        expect(wrapper.text()).toContain('Back.');
    });

    it('asks nothing while every run is over', async () => {
        page([run()]);

        await vi.advanceTimersByTimeAsync(9000);

        expect(jsonFetch).not.toHaveBeenCalled();
    });

    it('lists the schedule with its last ending, and runs a task on demand once at a time', async () => {
        const post = vi.spyOn(router, 'post').mockImplementation(() => undefined);
        const wrapper = page([], [daily]);
        const button = wrapper.findAll('button').find((candidate) => candidate.text() === 'Run now');

        expect(wrapper.text()).toContain('Clean up expired data');
        expect(wrapper.text()).toContain('Failed');

        await button?.trigger('click');
        await button?.trigger('click');

        expect(post).toHaveBeenCalledTimes(1);
        expect(post).toHaveBeenCalledWith('/settings.tasks.run', { name: 'cleanup' }, expect.anything());
        expect(wrapper.text()).toContain('Running');
    });

    it("opens and closes a run's errors", async () => {
        const wrapper = page([run({ errors: ['A title was not found.'] })]);
        const toggle = () => wrapper.findAll('button').find((candidate) => /errors?$/.test(candidate.text()));

        await toggle()?.trigger('click');
        expect(wrapper.text()).toContain('A title was not found.');

        await toggle()?.trigger('click');
        expect(wrapper.text()).not.toContain('A title was not found.');
    });

    it('clears the finished history once confirmed', async () => {
        const remove = vi.spyOn(router, 'delete').mockImplementation(() => undefined);

        await page([run()], [], true)
            .findAll('button')
            .find((candidate) => candidate.text() === 'Clear finished runs')
            ?.trigger('click');
        await flushPromises();

        expect(remove).toHaveBeenCalledWith('/settings.tasks.destroy', expect.anything());
    });

    it('words every status a run or a scheduled task can end in', () => {
        const runs = (['pending', 'started', 'success', 'failure'] as const).map((status, index) => run({ id: index, status }));
        const schedule = (['pending', 'started', 'success'] as const).map((lastStatus) => ({ ...daily, name: lastStatus, lastStatus }));
        const text = page(runs, schedule).text();

        for (const word of ['Pending', 'Started', 'Success', 'Failed']) {
            expect(text).toContain(word);
        }
    });

    it('keeps what it shows when the poll is not answered', async () => {
        vi.mocked(jsonFetch).mockResolvedValue(new Response(null, { status: 500 }));
        const wrapper = page([run({ status: 'started', summary: 'Processing entry 2…' })]);

        await vi.advanceTimersByTimeAsync(3000);
        await flushPromises();

        expect(wrapper.text()).toContain('Processing entry 2…');
    });

    it('keeps what a run noted apart from its errors, and a success with notes a success', async () => {
        const wrapper = page([run({ details: ['show:1 (Dark): no longer at the provider (kept as an archive entry).'] })]);
        const notes = () => wrapper.findAll('button').find((candidate) => /notes?$/.test(candidate.text()));

        expect(wrapper.text()).toContain('Success');
        expect(wrapper.text()).not.toContain('Finished with errors');
        expect(notes()?.text()).toBe('Show 1 note');

        await notes()?.trigger('click');
        expect(wrapper.text()).toContain('show:1 (Dark): no longer at the provider');
    });
});
