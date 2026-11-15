import type { JsonFetchOptions } from '@/lib/http';
import EventPage from '@/pages/media/EventPage.vue';
import { useMediaTypes } from '@/test/factories';
import type { EventPage as EventPageData } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const calls: { url: string; method: string }[] = [];

vi.mock('@/lib/http', () => ({
    jsonFetch: (url: string, { method = 'GET' }: JsonFetchOptions = {}) => {
        calls.push({ url, method });

        return Promise.resolve(new Response(JSON.stringify({ data: { hidden: method === 'PUT' } }), { status: 200 }));
    },
}));

function page(overrides: Partial<EventPageData> = {}, stream: string | null = 'https://www.youtube.com/watch?v=sgf') {
    return mount(EventPage, {
        props: {
            page: {
                event: {
                    provider: 'igdb',
                    ref: '6045',
                    name: 'Summer Game Fest 2026',
                    startsAt: '2026-10-20T19:00:00Z',
                    endsAt: '2026-10-20T21:00:00Z',
                    liveStreamUrl: stream,
                    logoPath: null,
                },
                description: 'The summer showcase.',
                timeZone: 'America/Los_Angeles',
                hidden: false,
                titles: [],
                ...overrides,
            },
        },
        global: { stubs: { AppLayout: { template: '<div><slot /></div>' }, Head: true } },
    });
}

beforeEach(() => {
    calls.length = 0;
    useMediaTypes();
});

describe('EventPage', () => {
    it('says when it runs, where it streams and in whose zone it is timed', () => {
        const wrapper = page();

        expect(wrapper.find('h1').text()).toBe('Summer Game Fest 2026');
        expect(wrapper.text()).toContain('America/Los_Angeles');
        expect(wrapper.find('a[target="_blank"]').attributes('href')).toBe('https://www.youtube.com/watch?v=sgf');
    });

    it('offers no stream link for an address that is no web address', () => {
        expect(page({}, 'javascript:alert(1)').find('a[target="_blank"]').exists()).toBe(false);
    });

    it('takes the event off the calendar and back, each time as the server answers, the live mark told at once', async () => {
        const reload = vi.spyOn(router, 'reload').mockImplementation(() => undefined);
        const wrapper = page();
        const toggle = () => wrapper.find('button');

        expect(toggle().text()).toBe('Hide from my calendar');

        await toggle().trigger('click');
        await flushPromises();

        expect(calls).toEqual([{ url: '/ui.events.hidden.update/igdb/6045', method: 'PUT' }]);
        expect(toggle().text()).toBe('Show in my calendar');

        await toggle().trigger('click');
        await flushPromises();

        expect(calls.at(-1)?.method).toBe('DELETE');
        expect(toggle().text()).toBe('Hide from my calendar');
        expect(reload).toHaveBeenCalledWith({ only: ['liveEvents'] });
    });

    it('offers no calendar toggle for an event long past, read from its provider', () => {
        expect(page({ hidden: null }).find('button').exists()).toBe(false);
    });

    it('marks the event live while it is on the air', () => {
        // The clock is frozen at 2026-10-10T12:00Z.
        const live = page({
            event: {
                provider: 'igdb',
                ref: '1',
                name: 'Live show',
                startsAt: '2026-10-10T11:30:00Z',
                endsAt: '2026-10-10T12:30:00Z',
                liveStreamUrl: null,
                logoPath: '/logo.jpg',
            },
        });

        expect(live.text()).toContain('Live');
        expect(live.find('img').attributes('alt')).toBe('Live show');
        expect(page().text()).not.toContain('Live');
    });
});
