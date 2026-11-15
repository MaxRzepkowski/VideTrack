import EventCountdownCard from '@/components/events/EventCountdownCard.vue';
import MediaTypeBadge from '@/components/media/MediaTypeBadge.vue';
import SortControl from '@/components/SortControl.vue';
import TypeFilter from '@/components/TypeFilter.vue';
import { vTooltip } from '@/directives/tooltip';
import Countdowns from '@/pages/Countdowns.vue';
import { useMediaTypes } from '@/test/factories';
import { setPageProps } from '@/test/page';
import type { EventCountdown, EventEntry, TypeCount } from '@/types/generated';
import { router } from '@inertiajs/vue3';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

let visits: [string, Record<string, unknown>][] = [];

beforeEach(() => {
    useMediaTypes();
    visits = [];
    vi.spyOn(router, 'get').mockImplementation(((url: string, params: Record<string, unknown>) => visits.push([url, params])) as never);
    setPageProps({});
});

const show = (ref: string, startsAt: string, endsAt: string): EventEntry => ({
    provider: 'igdb',
    ref,
    name: `Show ${ref}`,
    startsAt,
    endsAt,
    liveStreamUrl: null,
    logoPath: null,
});

const countdown = (event: EventEntry): EventCountdown => ({ event, mediumType: 'game', filterType: 'event' });

function page(entries: EventCountdown[] = [], types: TypeCount[] = []) {
    return mount(Countdowns, {
        props: {
            entries,
            types: [{ type: 'movie', count: 3 }, { type: 'show', count: 2 }, ...types],
            filters: { type: null, q: null, sort: 'premiere', dir: 'asc' },
            pagination: { currentPage: 1, totalPages: 1, total: 5 },
        },
        global: {
            directives: { tooltip: vTooltip },
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageToolbar: { template: '<div><slot name="search" /><slot name="filters" /><slot name="sort" /></div>' },
                Head: true,
                Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
            },
        },
    });
}

describe('Countdowns', () => {
    it('sorts by an axis it offers, and ignores one it does not', async () => {
        const wrapper = page();
        const sort = wrapper.findComponent(SortControl);

        sort.vm.$emit('update:sort', 'title');
        sort.vm.$emit('update:sort', 'popularity');

        expect(visits).toEqual([['/countdowns', { sort: 'title' }]]);
    });

    it('names every type behind "All"', () => {
        expect(page().findComponent(TypeFilter).find('option').text()).toBe('All (5)');
    });

    it('counts down to an event among the premieres, in the games colour, and marks one on the air live', () => {
        // The clock is frozen at 2026-10-10T12:00Z.
        const wrapper = page(
            [
                countdown(show('1', '2026-10-10T11:30:00Z', '2026-10-10T12:30:00Z')),
                countdown(show('2', '2026-10-11T12:00:00Z', '2026-10-11T13:00:00Z')),
            ],
            [{ type: 'event', count: 2 }],
        );
        const cards = wrapper.findAllComponents(EventCountdownCard);

        expect(cards).toHaveLength(2);
        expect(wrapper.find('h2').exists()).toBe(false);
        expect(cards[0]!.text()).toContain('Live');
        expect(cards[1]!.text()).not.toContain('Live');
        expect(cards[1]!.text()).toContain('Days');
        expect(cards[0]!.find('a').attributes('href')).toBe('/events.show/igdb/1');
        expect(cards[0]!.findComponent(MediaTypeBadge).props()).toMatchObject({ type: 'game', label: 'Event' });
        // Events are an option of their own in the type filter.
        expect(
            wrapper
                .findComponent(TypeFilter)
                .findAll('option')
                .map((option) => option.text()),
        ).toEqual(['All (7)', 'Movie (3)', 'TV (2)', 'Event (2)']);
    });
});
