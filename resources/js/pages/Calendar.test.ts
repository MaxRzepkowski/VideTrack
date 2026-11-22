import Calendar from '@/pages/Calendar.vue';
import { setPageProps } from '@/test/page';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const month = { month: '2026-10-01', start: '2026-09-28', end: '2026-11-01', days: [] };

let visits: string[] = [];

beforeEach(() => {
    vi.useFakeTimers();
    visits = [];
    vi.spyOn(router, 'get').mockImplementation(((url: string) => visits.push(url)) as never);
    setPageProps({});
});

afterEach(() => vi.useRealTimers());

function dateField() {
    return mount(Calendar, {
        props: {
            anchor: '2026-10-01',
            today: '2026-10-10',
            prev: '2026-09-01',
            next: '2026-11-01',
            month,
            feedUrl: '/feed',
            rssUrl: '/rss',
        },
        global: {
            stubs: { AppLayout: { template: '<div><slot /></div>' }, MonthGrid: true, Head: true, Link: true },
        },
    }).find('input[type="date"]');
}

describe('the date jump', () => {
    it('waits for the typing to settle, then goes to the date it settled on', async () => {
        const field = dateField();

        for (const value of ['0002-10-01', '0020-10-01', '0202-10-01', '2027-10-01']) {
            await field.setValue(value);
        }

        expect(visits).toEqual([]);

        vi.advanceTimersByTime(700);
        expect(visits).toEqual(['/calendar/2027-10-01']);
    });

    it('goes at once on Enter, and only once', async () => {
        const field = dateField();

        await field.setValue('2027-03-01');
        await field.trigger('keydown', { key: 'Enter' });
        await field.trigger('blur');
        vi.advanceTimersByTime(700);

        expect(visits).toEqual(['/calendar/2027-03-01']);
    });
});
