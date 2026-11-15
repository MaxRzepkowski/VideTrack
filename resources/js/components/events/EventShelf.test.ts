import EventShelf from '@/components/events/EventShelf.vue';
import ShowMoreToggle from '@/components/ShowMoreToggle.vue';
import type { EventEntry } from '@/types/generated';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const Link = { props: ['href'], template: '<a :href="href"><slot /></a>' };

// The clock is frozen at 2026-10-10T12:00Z.
const event = (ref: string, startsAt: string, endsAt: string): EventEntry => ({
    provider: 'igdb',
    ref,
    name: `Show ${ref}`,
    startsAt,
    endsAt,
    liveStreamUrl: null,
    logoPath: `/logo-${ref}.jpg`,
});

const shelf = (events: EventEntry[]) => mount(EventShelf, { props: { title: 'Shown at', events }, global: { stubs: { Link } } });

describe('EventShelf', () => {
    it('counts the events, opens each page and marks one on the air live', () => {
        const wrapper = shelf([
            event('1', '2026-10-10T11:00:00Z', '2026-10-10T13:00:00Z'),
            event('2', '2026-06-06T19:00:00Z', '2026-06-06T21:00:00Z'),
        ]);

        expect(wrapper.find('h2').text()).toBe('Shown at (2)');
        expect(wrapper.findAll('a').map((a) => a.attributes('href'))).toEqual(['/events.show/igdb/1', '/events.show/igdb/2']);
        expect(wrapper.findAll('a')[0]!.text()).toContain('Live');
        expect(wrapper.findAll('a')[1]!.text()).not.toContain('Live');
    });

    it('shows a row of four, the rest behind the toggle, and nothing without events', () => {
        const many = Array.from({ length: 6 }, (_, i) => event(String(i), '2026-06-06T19:00:00Z', '2026-06-06T21:00:00Z'));

        expect(shelf(many).findAll('a')).toHaveLength(4);
        expect(shelf(many).findComponent(ShowMoreToggle).props('hiddenCount')).toBe(2);
        expect(shelf([]).html()).toBe('<!--v-if-->');
    });
});
